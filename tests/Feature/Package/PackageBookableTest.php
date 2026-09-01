<?php

namespace Tests\Feature\Package;

use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * لا يوجد حالياً أي إجراء فعلي في المنصة يُلغي جلسة واحدة بمعزل عن الحجز
 * كاملاً — status='cancelled' على class_sessions قيمة موجودة في المخطط بلا
 * أي كود ينتجها (تُضبط هنا يدوياً لمحاكاتها، تماماً كما في PackageBusySlotsTest).
 * قبل هذا الإصلاح: package_schedules (تاريخ الجدول) منفصل تماماً عن
 * class_sessions الفعلية، فتبقى الباقة الجماعية "متاحة للحجز" رغم أن جلستها
 * الوحيدة الفعلية ملغاة، وينضم طالب جديد إليها فعلياً (راجع Package::isBookable).
 */
class PackageBookableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_group_package_whose_only_generated_session_is_cancelled_is_not_bookable(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $sessionDate = Carbon::now()->addWeek();
        $package = $this->createActiveGroupPackage($teacher, capacity: 10, sessionsCount: 1);
        $package->schedules()->create([
            'date' => $sessionDate->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $firstStudent = $this->createStudent();
        app(BookingService::class)->joinGroupPackage($firstStudent, $package);

        // إلغاء الجلسة الوحيدة يدوياً (لا مسار حقيقي في التطبيق يفعل هذا حالياً)
        $package->fresh()->bookings()->first()->sessions()->first()->update(['status' => 'cancelled']);

        $this->assertFalse($package->fresh()->isBookable());

        $secondStudent = $this->createStudent();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('هذه الباقة غير متاحة للحجز');

        app(BookingService::class)->joinGroupPackage($secondStudent, $package->fresh());
    }

    /** يبقى ظاهراً للطالب (موسوماً لاحقاً في الواجهة) بدل اختفائه بصمت — نفس فلسفة الباقة "المنتهية" */
    public function test_a_group_package_with_a_cancelled_session_still_appears_in_the_listing(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $sessionDate = Carbon::now()->addWeek();
        $package = $this->createActiveGroupPackage($teacher, capacity: 10, sessionsCount: 1);
        $package->schedules()->create([
            'date' => $sessionDate->toDateString(),
            'day_of_week' => $sessionDate->dayOfWeek,
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $firstStudent = $this->createStudent();
        app(BookingService::class)->joinGroupPackage($firstStudent, $package);
        $package->fresh()->bookings()->first()->sessions()->first()->update(['status' => 'cancelled']);

        $viewingStudent = $this->createStudent();
        $token = User::find($viewingStudent->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson("/api/teachers/{$teacher->id}/packages");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($package->id));
    }

    /**
     * الفردية لا تُنشئ جلساتها إلا عند كل حجز جديد ومستقل — إلغاء جلسة حجز
     * سابق لا يجب أن يمنع طالباً آخر من شراء الباقة نفسها بموعد جديد تماماً.
     * enrolled_count يُعاد صفره هنا يدوياً لعزل هذا الاختبار عن آلية
     * status→full التلقائية عند اكتمال النصاب (PackageObserver) — تلك آلية
     * منفصلة تماماً عن الفحص المقصود هنا (تجاهل isBookable() لحالة الجلسة
     * الملغاة تحديداً للباقات الفردية)، وليست ما يُختبر في هذه الحالة.
     */
    public function test_an_individual_package_remains_bookable_despite_a_past_cancelled_session(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher);
        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $firstStudent = $this->createStudent();
        $admin = User::factory()->admin()->create();
        $booking = app(BookingService::class)->createManualBooking(
            $firstStudent, $package, $admin, 'حجز سيُلغى', [['date' => $nextWednesday->toDateString(), 'start_time' => '09:00']],
        );
        $booking->sessions()->first()->update(['status' => 'cancelled']);
        $package->update(['enrolled_count' => 0]);

        $this->assertTrue($package->fresh()->isBookable());
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(): array
    {
        $user = User::factory()->teacher()->create(['timezone' => 'UTC']);
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher, $user->createToken('t')->plainTextToken];
    }

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create(['timezone' => 'UTC']);

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function createActiveIndividualPackage(Teacher $teacher): Package
    {
        $subject = Subject::create(['code' => 'pb-'.uniqid(), 'name_ar' => 'مادة']);
        $computed = app(PricingService::class)->calculateStudentPrice(100, 60, 1);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة فردية',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
            'schedule_timezone' => 'UTC',
        ]);
    }

    private function createActiveGroupPackage(Teacher $teacher, int $capacity, int $sessionsCount): Package
    {
        $subject = Subject::create(['code' => 'pb-'.uniqid(), 'name_ar' => 'مادة مجموعة']);
        $computed = app(PricingService::class)->calculateStudentPrice(100, 60, $sessionsCount);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة مجموعة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => $capacity,
            'sessions_count' => $sessionsCount,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
            'schedule_timezone' => 'UTC',
        ]);
    }
}
