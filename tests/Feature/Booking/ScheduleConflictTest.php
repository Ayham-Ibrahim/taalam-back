<?php

namespace Tests\Feature\Booking;

use App\Models\Course;
use App\Models\CourseField;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BookingService;
use App\Services\EnrollmentService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * كان النظام يسمح لطالب بحجز/الانضمام لجلسة تتقاطع زمنياً تماماً مع جلسة
 * أخرى له بالفعل (مؤكَّدة أو قيد الدفع) — بلا أي تحقق أو رسالة، فيُنشأ الحجز
 * بنجاح رغم التعارض الكامل. هذا الملف يثبت أن كل مسارات إنشاء الجلسات
 * (حجز فردي ذاتي/يدوي، انضمام جماعي، تسجيل دورة) ترفض الآن أي تعارض صراحة،
 * برسالة "لا يمكن الحجز في هذا الوقت لوجود جلسة أخرى متعارضة."، وبلا أي أثر
 * في قاعدة البيانات (لا حجز جديد ولا جلسة جديدة) عند الرفض.
 */
class ScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    private const CONFLICT_MESSAGE = 'لا يمكن الحجز في هذا الوقت لوجود جلسة أخرى متعارضة.';

    public function test_requesting_an_individual_booking_is_rejected_via_api_when_it_overlaps_an_existing_confirmed_session(): void
    {
        [$teacherA, $teacherATokenUser] = $this->createVerifiedTeacher();
        $packageA = $this->createActiveIndividualPackage($teacherA, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $nextWednesday = Carbon::now()->next(3);
        $packageA->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        // حجز أول مؤكَّد (عبر الحجز اليدوي، مؤكَّد فوراً) الساعة 10:00
        [$teacherB] = $this->createVerifiedTeacher();
        $packageB = $this->createActiveIndividualPackage($teacherB, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $packageB->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);
        $admin = User::factory()->admin()->create();
        app(BookingService::class)->createManualBooking(
            $student, $packageB, $admin, 'حجز أول', $nextWednesday->toDateString(), '10:00',
        );

        // محاولة حجز فردي ثانٍ (باقة مختلفة، معلم مختلف) بنفس اليوم والوقت تماماً
        $response = $this->as($studentToken)->postJson("/api/packages/{$packageA->id}/bookings/individual", [
            'date' => $nextWednesday->toDateString(),
            'start_time' => '10:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.schedule.0', self::CONFLICT_MESSAGE);

        $this->assertSame(0, DB::table('bookings')->where('package_id', $packageA->id)->count());
    }

    public function test_approving_an_individual_request_is_rejected_when_a_conflict_appeared_after_the_request_was_made(): void
    {
        [$teacherA] = $this->createVerifiedTeacher();
        $packageA = $this->createActiveIndividualPackage($teacherA, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $student = $this->createStudent();

        $nextWednesday = Carbon::now()->next(3);
        $packageA->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        // الطالب يقدّم طلب حجز فردي أولاً — لا تعارض بعد
        $requested = app(BookingService::class)->requestIndividualBooking(
            $student, $packageA, $nextWednesday->toDateString(), '10:00',
        );
        $this->assertSame('pending_teacher_confirmation', $requested->status);

        // بين الطلب والموافقة، تأكَّد حجز آخر لنفس الطالب بنفس التوقيت تماماً (سباق واقعي)
        [$teacherB] = $this->createVerifiedTeacher();
        $packageB = $this->createActiveIndividualPackage($teacherB, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $packageB->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);
        $admin = User::factory()->admin()->create();
        app(BookingService::class)->createManualBooking(
            $student, $packageB, $admin, 'حجز متسارع', $nextWednesday->toDateString(), '10:00',
        );

        // الآن معلم الطلب الأول يوافق — يجب أن تُرفَض رغم أن الطلب نفسه كان سليماً وقت تقديمه
        $teacherAUser = User::find($teacherA->user_id);

        try {
            app(BookingService::class)->approveIndividualRequest($requested, $teacherAUser);
            $this->fail('كان يجب رفض الموافقة بسبب التعارض');
        } catch (ValidationException $e) {
            $this->assertSame(self::CONFLICT_MESSAGE, $e->errors()['schedule'][0]);
        }

        $requested->refresh();
        $this->assertSame('pending_teacher_confirmation', $requested->status);
        $this->assertSame(0, DB::table('class_sessions')->where('booking_id', $requested->id)->count());
    }

    public function test_joining_a_group_package_is_rejected_when_its_schedule_overlaps_an_existing_session(): void
    {
        [$teacherA] = $this->createVerifiedTeacher();
        $sessionDate = Carbon::now()->addWeek();
        $groupPackage = $this->createActiveGroupPackage($teacherA, capacity: 10, sessionsCount: 1, teacherPrice: 100, margin: 60);
        $groupPackage->schedules()->create([
            'date' => $sessionDate->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '14:00',
            'end_time' => '15:00',
        ]);

        $student = $this->createStudent();

        // حجز فردي مؤكَّد مسبقاً لنفس الطالب، نفس اللحظة تماماً، عند معلم آخر
        [$teacherB] = $this->createVerifiedTeacher();
        $individualPackage = $this->createActiveIndividualPackage($teacherB, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $individualPackage->schedules()->create(['day_of_week' => $sessionDate->dayOfWeek]);
        $admin = User::factory()->admin()->create();
        app(BookingService::class)->createManualBooking(
            $student, $individualPackage, $admin, 'حجز سابق', $sessionDate->toDateString(), '14:00',
        );

        $this->expectException(ValidationException::class);

        try {
            app(BookingService::class)->joinGroupPackage($student, $groupPackage);
        } catch (ValidationException $e) {
            $this->assertSame(self::CONFLICT_MESSAGE, $e->errors()['schedule'][0]);
            // لا حجز جديد لهذه الباقة، ولا الطالب أصبح حاضراً على جلساتها
            $this->assertSame(0, DB::table('bookings')->where('package_id', $groupPackage->id)->count());
            $this->assertSame(0, DB::table('session_attendees')->where('student_id', $student->id)
                ->whereIn('class_session_id', $groupPackage->fresh()->schedules->pluck('id'))->count());

            throw $e;
        }
    }

    public function test_manual_booking_by_admin_is_also_rejected_on_conflict(): void
    {
        [$teacherA] = $this->createVerifiedTeacher();
        $packageA = $this->createActiveIndividualPackage($teacherA, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $student = $this->createStudent();
        $admin = User::factory()->admin()->create();

        $nextWednesday = Carbon::now()->next(3);
        $packageA->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        app(BookingService::class)->createManualBooking(
            $student, $packageA, $admin, 'الحجز الأول', $nextWednesday->toDateString(), '16:00',
        );

        [$teacherB] = $this->createVerifiedTeacher();
        $packageB = $this->createActiveIndividualPackage($teacherB, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $packageB->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(self::CONFLICT_MESSAGE);

        app(BookingService::class)->createManualBooking(
            $student, $packageB, $admin, 'حجز متعارض', $nextWednesday->toDateString(), '16:00',
        );
    }

    public function test_enrolling_in_a_course_is_rejected_when_a_session_overlaps_an_existing_package_booking(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $student = $this->createStudent();
        $admin = User::factory()->admin()->create();

        $courseStart = Carbon::now()->next(1)->startOfDay();
        $center = $this->createVerifiedCenter();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $courseStart, end: $courseStart->copy()->addDays(6));
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        // حجز فردي مؤكَّد يتقاطع مع أول جلسة من جلسات الدورة (يوم الاثنين 09:00)
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $package->schedules()->create(['day_of_week' => 1]);
        app(BookingService::class)->createManualBooking(
            $student, $package, $admin, 'حجز سابق', $courseStart->toDateString(), '09:00',
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(self::CONFLICT_MESSAGE);

        app(EnrollmentService::class)->initiateEnrollment($student, $course);
    }

    public function test_booking_a_genuinely_different_time_still_succeeds(): void
    {
        [$teacherA] = $this->createVerifiedTeacher();
        $packageA = $this->createActiveIndividualPackage($teacherA, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $student = $this->createStudent();
        $admin = User::factory()->admin()->create();

        $nextWednesday = Carbon::now()->next(3);
        $packageA->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);
        app(BookingService::class)->createManualBooking(
            $student, $packageA, $admin, 'الحجز الأول', $nextWednesday->toDateString(), '09:00',
        );

        [$teacherB] = $this->createVerifiedTeacher();
        $packageB = $this->createActiveIndividualPackage($teacherB, teacherPrice: 100, margin: 60, sessionsCount: 1);
        $packageB->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        // نفس اليوم، لكن بعد انتهاء الجلسة الأولى بساعتين — بلا أي تعارض
        $booking = app(BookingService::class)->createManualBooking(
            $student, $packageB, $admin, 'حجز لاحق غير متعارض', $nextWednesday->toDateString(), '11:00',
        );

        $this->assertSame('confirmed', $booking->status);
        $this->assertCount(1, $booking->sessions);
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(string $timezone = 'UTC'): array
    {
        $user = User::factory()->teacher()->create(['timezone' => $timezone]);
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher, $user->createToken('t')->plainTextToken];
    }

    private function createVerifiedCenter(string $timezone = 'UTC'): Teacher
    {
        $user = User::factory()->teacher()->create(['timezone' => $timezone]);

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => 'training_center', 'status' => 'verified']);
    }

    private function createStudent(string $timezone = 'UTC'): Student
    {
        $user = User::factory()->student()->create(['timezone' => $timezone]);

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function createActiveIndividualPackage(Teacher $teacher, float $teacherPrice, float $margin, int $sessionsCount = 1): Package
    {
        $subject = Subject::create(['code' => 'sc-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);
        $computed = app(PricingService::class)->calculateStudentPrice($teacherPrice, $margin, $sessionsCount);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة فردية',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => $sessionsCount,
            'teacher_price' => $teacherPrice,
            'platform_margin_percent' => $margin,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
            'schedule_timezone' => $teacher->loadMissing('user')->user->timezone,
        ]);
    }

    private function createActiveGroupPackage(Teacher $teacher, int $capacity, int $sessionsCount, float $teacherPrice, float $margin): Package
    {
        $subject = Subject::create(['code' => 'sc-'.uniqid(), 'name_ar' => 'مادة مجموعة', 'education_type' => 'school']);
        $computed = app(PricingService::class)->calculateStudentPrice($teacherPrice, $margin, $sessionsCount);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة مجموعة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => $capacity,
            'sessions_count' => $sessionsCount,
            'teacher_price' => $teacherPrice,
            'platform_margin_percent' => $margin,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
            'schedule_timezone' => $teacher->loadMissing('user')->user->timezone,
        ]);
    }

    private function createActiveCourse(Teacher $center, float $teacherPrice, float $margin, Carbon $start, Carbon $end, int $maxSeats = 20): Course
    {
        $field = CourseField::create(['code' => 'sc-field-'.uniqid(), 'name_ar' => 'مجال']);
        $computed = app(PricingService::class)->calculateStudentPrice($teacherPrice, $margin);

        return Course::create([
            'teacher_id' => $center->id,
            'title' => 'دورة',
            'course_field_id' => $field->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_sessions' => 4,
            'max_seats' => $maxSeats,
            'provider_price' => $teacherPrice,
            'platform_margin_percent' => $margin,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
            'schedule_timezone' => $center->loadMissing('user')->user->timezone,
        ]);
    }
}
