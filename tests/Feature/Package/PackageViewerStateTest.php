<?php

namespace Tests\Feature\Package;

use App\Models\Booking;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * بطاقات باقات ملف المعلم كانت لا تعرف شيئاً عن حجز الطالب — يضغط "اختر الباقة"
 * ويملأ النموذج ثم يُرفض بـ "اشتراك قائم". my_booking يعرض حالته مسبقاً، بنفس
 * شرط المنع (Booking::open) حرفياً.
 */
class PackageViewerStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_individual_request_appears_on_its_package_only(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $other = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();
        $token = User::find($student->user_id)->createToken('t')->plainTextToken;

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $this->as($token)->postJson("/api/packages/{$package->id}/bookings/individual", [
            'slots' => [['date' => $nextWednesday->toDateString(), 'start_time' => '09:00']],
        ])->assertStatus(201);

        $data = collect($this->as($token)->getJson("/api/teachers/{$teacher->id}/packages")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('pending_teacher_confirmation', $data[$package->id]['my_booking']['status']);
        $this->assertNotNull($data[$package->id]['my_booking']['requested_at']);
        $this->assertNull($data[$other->id]['my_booking']);
        $this->assertFalse($data[$package->id]['trial_used']);
    }

    public function test_guest_gets_no_viewer_fields(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);

        $item = $this->getJson("/api/teachers/{$teacher->id}/packages")->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('my_booking', $item);
        $this->assertArrayNotHasKey('trial_used', $item);
    }

    public function test_pending_payment_with_expired_hold_is_neither_shown_nor_blocking(): void
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

        $student = $this->createStudent();
        $token = User::find($student->user_id)->createToken('t')->plainTextToken;

        $booking = app(BookingService::class)->joinGroupPackage($student, $package);
        $this->assertSame('pending_payment', $booking->status);

        $item = $this->as($token)->getJson("/api/teachers/{$teacher->id}/packages")->json('data.0');
        $this->assertSame('pending_payment', $item['my_booking']['status']);
        $this->assertNotNull($item['my_booking']['hold_expires_at']);

        // المهلة انقضت ولم يمر المجدول بعد — الحالة ما زالت pending_payment في القاعدة
        Booking::whereKey($booking->id)->update(['hold_expires_at' => now()->subMinute()]);

        $item = $this->as($token)->getJson("/api/teachers/{$teacher->id}/packages")->json('data.0');
        $this->assertNull($item['my_booking']);

        // ولا يمنع حجزاً جديداً — كان يُرفض بـ "اشتراك قائم" حتى يلحق المجدول
        app(BookingService::class)->joinGroupPackage($student, Package::find($package->id));
        $this->assertSame(2, Booking::where('student_id', $student->id)->count());
    }

    public function test_used_trial_is_flagged(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $trial = Package::create([
            'teacher_id' => $teacher->id, 'title' => 'تعريفية', 'session_format' => 'individual', 'is_trial' => true,
            'capacity' => 1, 'sessions_count' => 1, 'teacher_price' => 0, 'platform_margin_percent' => 0,
            'student_price' => 0, 'platform_revenue' => 0, 'status' => 'active', 'approved_at' => now(),
        ]);
        $student = $this->createStudent();
        $token = User::find($student->user_id)->createToken('t')->plainTextToken;

        $item = $this->as($token)->getJson("/api/teachers/{$teacher->id}/packages")->json('data.0');
        $this->assertFalse($item['trial_used']);

        Booking::create([
            'reference' => 'TRIAL-1',
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $trial->id,
            'amount_paid' => 0,
            'teacher_amount' => 0,
            'platform_amount' => 0,
            'margin_percent_snapshot' => 0,
            'sessions_total' => 1,
            'sessions_used' => 1,
            'sessions_remaining' => 0,
            'status' => 'completed',
        ]);

        $item = $this->as($token)->getJson("/api/teachers/{$teacher->id}/packages")->json('data.0');
        $this->assertTrue($item['trial_used']);
        $this->assertNull($item['my_booking']);
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
