<?php

namespace Tests\Feature\Package;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "الوقت المتعارض يجب أن يظهر كـ غير متاح في الواجهة" — هذا الـ endpoint هو
 * ما يغذّي ذلك العرض المسبق قبل الإرسال (ليس الفحص الملزم، ذاك يبقى في
 * ScheduleConflictService وقت الحجز الفعلي).
 */
class PackageBusySlotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_teachers_busy_ranges_for_the_requested_local_day(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $packageA = $this->createActiveIndividualPackage($teacher);
        $packageB = $this->createActiveIndividualPackage($teacher);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $day = Carbon::now('UTC')->addWeek()->startOfDay();
        $busySession = ClassSession::create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $day->copy()->setTime(10, 0),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        $response = $this->as($studentToken)->getJson(
            "/api/packages/{$packageB->id}/busy-slots?date=".$day->toDateString()
        );

        $response->assertStatus(200);
        $slots = $response->json('data');
        $this->assertCount(1, $slots);
        $this->assertSame($busySession->scheduled_at->toIso8601String(), $slots[0]['start']);
    }

    /** جلسة معلم آخر لا علاقة لها إطلاقاً بهذه الباقة — لا يجوز ظهورها كوقت مشغول لهذا المعلم */
    public function test_it_does_not_include_another_teachers_sessions(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher);

        [$otherTeacher] = $this->createVerifiedTeacher();
        $day = Carbon::now('UTC')->addWeek()->startOfDay();
        ClassSession::create([
            'teacher_id' => $otherTeacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $day->copy()->setTime(10, 0),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson(
            "/api/packages/{$package->id}/busy-slots?date=".$day->toDateString()
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_cancelled_sessions_are_not_counted_as_busy(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher);
        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $day = Carbon::now('UTC')->addWeek()->startOfDay();
        ClassSession::create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $day->copy()->setTime(10, 0),
            'duration_min' => 60,
            'status' => 'cancelled',
        ]);

        $response = $this->as($studentToken)->getJson(
            "/api/packages/{$package->id}/busy-slots?date=".$day->toDateString()
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * "جلسة شبح": صف class_sessions يبقى scheduled إلى الأبد بعد انتهاء مهلة
     * دفع حجزه (BookingService::expireStalePendingPayments لا يلمس الجلسات
     * إطلاقاً) — يجب ألا تُحتسَب كوقت مشغول حقيقي، وإلا ظهرت للطالب أوقات
     * "غير متاحة" لا تقابل أي جلسة فعلية في جدول المعلم.
     */
    public function test_a_session_of_an_expired_booking_is_not_counted_as_busy(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher);
        $student = $this->createStudent();
        $admin = User::factory()->admin()->create();

        $day = Carbon::now('UTC')->addWeek()->startOfDay();
        $package->schedules()->create(['day_of_week' => $day->dayOfWeek]);
        $booking = app(BookingService::class)->createManualBooking(
            $student, $package, $admin, 'حجز سينتهي', [['date' => $day->toDateString(), 'start_time' => '10:00']],
        );
        // الجلسة تبقى scheduled كما هي — فقط الحجز نفسه ينتهي (نفس أثر expireStalePendingPayments)
        $booking->update(['status' => 'expired']);
        // enrolled_count لا يُخفَّض تلقائياً عند انتهاء حجز (فجوة منفصلة معروفة) —
        // نعزل هذا الاختبار عنها كي لا يُحجَب الوصول للـ endpoint بحجة status='full'
        $package->update(['enrolled_count' => 0]);

        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;
        $response = $this->as($studentToken)->getJson(
            "/api/packages/{$package->id}/busy-slots?date=".$day->toDateString()
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * سبب الأزواج المكرَّرة تحديداً: انتهاء حجز أول لا يمنع تعارض جدولة (نفس
     * قاعدة ScheduleConflictService)، فيحجز طالب آخر نفس الموعد بالضبط —
     * جلسة شبح + جلسة حقيقية بنفس الوقت معاً. يجب أن تظهر مرة واحدة فقط.
     */
    public function test_a_real_session_at_the_same_time_as_an_expired_ghost_appears_only_once(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher);
        $ghostStudent = $this->createStudent();
        $realStudent = $this->createStudent();
        $admin = User::factory()->admin()->create();

        $day = Carbon::now('UTC')->addWeek()->startOfDay();
        $package->schedules()->create(['day_of_week' => $day->dayOfWeek]);
        $ghostBooking = app(BookingService::class)->createManualBooking(
            $ghostStudent, $package, $admin, 'حجز سينتهي', [['date' => $day->toDateString(), 'start_time' => '10:00']],
        );
        $ghostBooking->update(['status' => 'expired']);
        $package->update(['enrolled_count' => 0]);

        $otherPackage = $this->createActiveIndividualPackage($teacher);
        $otherPackage->schedules()->create(['day_of_week' => $day->dayOfWeek]);
        app(BookingService::class)->createManualBooking(
            $realStudent, $otherPackage, $admin, 'حجز حقيقي بنفس الموعد', [['date' => $day->toDateString(), 'start_time' => '10:00']],
        );

        $viewerToken = User::find($this->createStudent()->user_id)->createToken('t')->plainTextToken;
        $response = $this->as($viewerToken)->getJson(
            "/api/packages/{$package->id}/busy-slots?date=".$day->toDateString()
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher, $user->createToken('t')->plainTextToken];
    }

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create();

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function createActiveIndividualPackage(Teacher $teacher): Package
    {
        $subject = Subject::create(['code' => 'bs-'.uniqid(), 'name_ar' => 'مادة']);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);
    }
}
