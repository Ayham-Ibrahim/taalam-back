<?php

namespace Tests\Feature\Booking;

use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * كان النظام يسمح لطالب بالاشتراك في نفس الباقة أكثر من مرة قبل انتهاء
 * اشتراكه القائم — لا منع في الانضمام لباقة جماعية ولا في الحجز اليدوي من
 * الأدمن (الحجز الفردي الذاتي كان الوحيد المحمي جزئياً، برسالة مختلفة عمّا
 * هو مطلوب الآن). هذا الملف يثبت أن الطلاب لا يستطيعون امتلاك أكثر من
 * اشتراك قائم واحد بنفس الباقة عبر أي مسار، وأن انتهاء/إلغاء الاشتراك
 * السابق يعيد فتح الباب للاشتراك من جديد.
 */
class DuplicateSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const DUPLICATE_MESSAGE = 'لا يمكن الاشتراك في هذه الباقة لأن لديك اشتراكًا قائمًا لم ينتهِ بعد.';

    public function test_individual_booking_request_is_rejected_via_api_when_a_booking_for_the_same_package_is_already_open(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $this->as($studentToken)->postJson("/api/packages/{$package->id}/bookings/individual", [
            'slots' => [['date' => $nextWednesday->toDateString(), 'start_time' => '09:00']],
        ])->assertStatus(201);

        $response = $this->as($studentToken)->postJson("/api/packages/{$package->id}/bookings/individual", [
            'slots' => [['date' => $nextWednesday->toDateString(), 'start_time' => '20:00']],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.package.0', self::DUPLICATE_MESSAGE);

        $this->assertSame(1, DB::table('bookings')->where('package_id', $package->id)->count());
    }

    public function test_joining_a_group_package_twice_is_rejected(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveGroupPackage($teacher, capacity: 10, sessionsCount: 2, teacherPrice: 100, margin: 60);
        $sessionDate = Carbon::now()->addWeek();
        $package->schedules()->create([
            'date' => $sessionDate->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);
        $package->schedules()->create([
            'date' => $sessionDate->copy()->addWeek()->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $student = $this->createStudent();
        app(BookingService::class)->joinGroupPackage($student, $package);

        $this->expectException(ValidationException::class);

        try {
            app(BookingService::class)->joinGroupPackage($student, $package);
        } catch (ValidationException $e) {
            $this->assertSame(self::DUPLICATE_MESSAGE, $e->errors()['package'][0]);
            $this->assertSame(1, DB::table('bookings')->where('package_id', $package->id)->where('student_id', $student->id)->count());

            throw $e;
        }
    }

    public function test_a_different_student_can_still_join_the_same_group_package(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveGroupPackage($teacher, capacity: 10, sessionsCount: 1, teacherPrice: 100, margin: 60);
        $sessionDate = Carbon::now()->addWeek();
        $package->schedules()->create([
            'date' => $sessionDate->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $studentA = $this->createStudent();
        $studentB = $this->createStudent();

        app(BookingService::class)->joinGroupPackage($studentA, $package);
        $bookingB = app(BookingService::class)->joinGroupPackage($studentB, $package);

        $this->assertSame('pending_payment', $bookingB->status);
        $this->assertSame(2, DB::table('bookings')->where('package_id', $package->id)->count());
    }

    public function test_manual_booking_by_admin_is_also_rejected_when_a_booking_is_already_open(): void
    {
        // باقة جماعية (نصاب > 1) عمداً — الفردية نصابها 1 دائماً، فتصير full
        // تلقائياً بعد أول حجز، ما يُخفي الفحص الذي نختبره هنا وراء رفض آخر
        // (نصاب مكتمل) بدل رسالة التكرار المقصودة تحديداً.
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveGroupPackage($teacher, capacity: 5, sessionsCount: 1, teacherPrice: 100, margin: 60);
        $sessionDate = Carbon::now()->addWeek();
        $package->schedules()->create([
            'date' => $sessionDate->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);
        $student = $this->createStudent();
        $admin = User::factory()->admin()->create();

        app(BookingService::class)->createManualBooking($student, $package, $admin, 'الحجز الأول');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(self::DUPLICATE_MESSAGE);

        app(BookingService::class)->createManualBooking($student, $package, $admin, 'محاولة ثانية');
    }

    public function test_student_can_rebook_the_same_package_after_the_previous_booking_was_cancelled(): void
    {
        // طلب حجز فردي عمداً (لا حجز يدوي/انضمام جماعي) — لا يُنشئ أي جلسة قبل
        // موافقة المعلم، فيبقى الاختبار معزولاً تماماً لفحص "التكرار" وحده، دون
        // تدخل فحص تعارض المواعيد أو قفل النصاب (كلاهما يتفعّلان فقط بعد إنشاء
        // جلسات فعلية، وهو أمر منفصل تماماً عن هذا الفحص).
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $slot = [['date' => $nextWednesday->toDateString(), 'start_time' => '09:00']];
        $first = app(BookingService::class)->requestIndividualBooking($student, $package, $slot);
        $first->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        // بعد إلغاء الاشتراك السابق، يجب السماح بالاشتراك من جديد بنفس الباقة
        $second = app(BookingService::class)->requestIndividualBooking($student, $package, $slot);

        $this->assertSame('pending_teacher_confirmation', $second->status);
        $this->assertSame(2, DB::table('bookings')->where('package_id', $package->id)->count());
    }

    public function test_student_with_an_open_booking_can_still_book_a_different_package(): void
    {
        [$teacherA] = $this->createVerifiedTeacher();
        $packageA = $this->createActiveIndividualPackage($teacherA, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();
        $admin = User::factory()->admin()->create();

        $nextWednesday = Carbon::now()->next(3);
        $packageA->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);
        app(BookingService::class)->createManualBooking(
            $student, $packageA, $admin, 'حجز أول', [['date' => $nextWednesday->toDateString(), 'start_time' => '09:00']],
        );

        [$teacherB] = $this->createVerifiedTeacher();
        $packageB = $this->createActiveIndividualPackage($teacherB, teacherPrice: 100, margin: 60);
        $packageB->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $second = app(BookingService::class)->createManualBooking(
            $student, $packageB, $admin, 'باقة مختلفة', [['date' => $nextWednesday->toDateString(), 'start_time' => '20:00']],
        );

        $this->assertSame('confirmed', $second->status);
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

    private function createActiveIndividualPackage(Teacher $teacher, float $teacherPrice, float $margin, int $sessionsCount = 1): Package
    {
        $subject = Subject::create(['code' => 'ds-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);
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
        ]);
    }

    private function createActiveGroupPackage(Teacher $teacher, int $capacity, int $sessionsCount, float $teacherPrice, float $margin): Package
    {
        $subject = Subject::create(['code' => 'ds-'.uniqid(), 'name_ar' => 'مادة مجموعة', 'education_type' => 'school']);
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
        ]);
    }
}
