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
use Tests\TestCase;

/**
 * الأمر الجذري الذي يحمي منه هذا الملف: وقت أدخله مستخدم (طالب/معلم) بمنطقته
 * الزمنية الخاصة (مثال: 15:00 بتوقيت طوكيو) كان يُخزَّن حرفياً كأنه UTC بلا أي
 * تحويل فعلي، فيظهر مُزاحاً لدى أي طرف آخر يقرأه من منطقة زمنية مختلفة —
 * بالضبط ما أبلغ عنه المستخدم (طالب حجز 15:00، ظهرت للمعلم 18:00).
 */
class SchedulingTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_booking_converts_students_local_time_to_utc_correctly(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);

        // طوكيو UTC+9 — لا علاقة له بمنطقة السيرفر (UTC) ولا بأي منطقة أخرى
        $student = $this->createStudent('Asia/Tokyo');

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $requested = app(BookingService::class)->requestIndividualBooking(
            $student, $package, $nextWednesday->toDateString(), '15:00',
        );

        $this->assertSame('Asia/Tokyo', $requested->requested_timezone);

        $teacherUser = User::find($teacher->user_id);
        $booking = app(BookingService::class)->approveIndividualRequest($requested, $teacherUser);

        $session = $booking->sessions()->first();

        // 15:00 طوكيو (UTC+9) = 06:00 UTC — القيمة المخزَّنة فعلياً في قاعدة البيانات
        $this->assertSame('06:00:00', $session->scheduled_at->format('H:i:s'));

        // وعند إعادة عرضها بتوقيت طوكيو تعود بالضبط لما اختاره الطالب — لا انزياح إطلاقاً
        $this->assertSame('15:00', $session->scheduled_at->copy()->setTimezone('Asia/Tokyo')->format('H:i'));
    }

    public function test_manual_booking_uses_the_targeted_students_timezone_not_the_admins(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent('Asia/Tokyo');
        $admin = User::factory()->admin()->create(['timezone' => 'UTC']);

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $booking = app(BookingService::class)->createManualBooking(
            $student, $package, $admin, 'تسوية شكوى', $nextWednesday->toDateString(), '15:00',
        );

        $session = $booking->sessions()->first();

        // منطقة الطالب (طوكيو) هي المرجع، لا منطقة الأدمن (UTC) رغم أن الأدمن من أنشأ الحجز
        $this->assertSame('06:00:00', $session->scheduled_at->format('H:i:s'));
    }

    public function test_group_package_schedule_uses_the_creating_teachers_timezone(): void
    {
        [$teacher] = $this->createVerifiedTeacher('Asia/Tokyo');
        $package = $this->createActiveGroupPackage($teacher, capacity: 5, sessionsCount: 1, teacherPrice: 100, margin: 60);

        $sessionDate = Carbon::now()->addWeek();
        $package->schedules()->create([
            'date' => $sessionDate->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $student = $this->createStudent('UTC');
        $booking = app(BookingService::class)->joinGroupPackage($student, $package);

        $session = $booking->sessions()->first();

        // 09:00 بتوقيت المعلم (طوكيو، UTC+9) = 00:00 UTC
        $this->assertSame('00:00:00', $session->scheduled_at->format('H:i:s'));
    }

    public function test_course_schedule_uses_the_creating_centers_timezone(): void
    {
        $center = $this->createVerifiedCenter('Asia/Tokyo');
        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6));
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $student = $this->createStudent('UTC');
        $enrollment = app(EnrollmentService::class)->initiateEnrollment($student, $course);

        $session = $enrollment->course->sessions()->first();

        $this->assertSame('00:00:00', $session->scheduled_at->format('H:i:s'));
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

    private function createActiveIndividualPackage(Teacher $teacher, float $teacherPrice, float $margin, int $sessionsCount = 4): Package
    {
        $subject = Subject::create(['code' => 'tz-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);
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
            // يوازي ما يحصل فعلياً في PackageService::createDraft عند الإنشاء الحقيقي
            'schedule_timezone' => $teacher->loadMissing('user')->user->timezone,
        ]);
    }

    private function createActiveGroupPackage(Teacher $teacher, int $capacity, int $sessionsCount, float $teacherPrice, float $margin): Package
    {
        $subject = Subject::create(['code' => 'tz-'.uniqid(), 'name_ar' => 'مادة مجموعة', 'education_type' => 'school']);
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
        $field = CourseField::create(['code' => 'tz-field-'.uniqid(), 'name_ar' => 'مجال']);
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
