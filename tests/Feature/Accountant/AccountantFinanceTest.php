<?php

namespace Tests\Feature\Accountant;

use App\Models\Package;
use App\Models\Payout;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المحاسب: الصلاحيات المالية فقط — عرض/اعتماد/تسجيل دفع المستحقات، وعرض
 * الحجوزات — دون توليد المستحقات أو باقي إدارة المنصة أو إنشاء حسابات.
 */
class AccountantFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_list_approve_and_mark_payouts_paid_and_export(): void
    {
        [$teacher, $payout] = $this->pendingPayoutFromAdmin();
        $accountantToken = $this->accountantToken();

        $this->as($accountantToken)->getJson('/api/payouts')->assertStatus(200);
        $this->as($accountantToken)->postJson("/api/payouts/{$payout->id}/approve")->assertStatus(200);
        $this->as($accountantToken)->postJson("/api/payouts/{$payout->id}/mark-paid", [
            'transfer_reference' => 'REF-ACC-1',
        ])->assertStatus(200);
        $this->assertSame('paid', $payout->fresh()->status);

        $this->as($accountantToken)->get('/api/payouts/export')->assertStatus(200);
    }

    public function test_accountant_can_see_all_bookings_for_finance_review(): void
    {
        $this->pendingPayoutFromAdmin();

        $this->as($this->accountantToken())->getJson('/api/bookings')->assertStatus(200);
    }

    public function test_accountant_cannot_generate_payouts(): void
    {
        [$teacher] = $this->pendingPayoutFromAdmin();

        $this->as($this->accountantToken())->postJson("/api/teachers/{$teacher->id}/payouts/generate", [
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_accountant_cannot_access_admin_only_areas(): void
    {
        $this->as($this->accountantToken())->getJson('/api/students')->assertStatus(403);
    }

    public function test_only_an_admin_can_create_accountant_accounts(): void
    {
        $payload = [
            'name' => 'محاسب تجريبي',
            'email' => 'accountant.test@example.com',
            'password' => 'secret-pass-123',
        ];

        $this->as($this->accountantToken())->postJson('/api/accountants', $payload)->assertStatus(403);

        $admin = User::factory()->admin()->create();
        $this->as($admin->createToken('t')->plainTextToken)
            ->postJson('/api/accountants', $payload)
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'accountant.test@example.com', 'role' => 'accountant']);
    }

    private function accountantToken(): string
    {
        $accountant = User::factory()->create(['role' => 'accountant']);

        return $accountant->createToken('t')->plainTextToken;
    }

    /** @return array{0: Teacher, 1: Payout} */
    private function pendingPayoutFromAdmin(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, 100, 4);

        $admin = User::factory()->admin()->create();
        $generate = $this->as($admin->createToken('t')->plainTextToken)->postJson(
            "/api/teachers/{$teacher->id}/payouts/generate",
            ['period_start' => now()->subDay()->toDateString(), 'period_end' => now()->addDay()->toDateString()],
        )->assertStatus(201);

        return [$teacher, Payout::findOrFail($generate->json('data.id'))];
    }

    private function createCompletedPackageSession(Teacher $teacher, float $teacherPrice, int $sessionsTotal): void
    {
        $subject = Subject::create(['code' => 'acc-'.uniqid(), 'name_ar' => 'مادة']);
        $computed = app(PricingService::class)->calculateStudentPrice($teacherPrice, 60, $sessionsTotal);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => $sessionsTotal,
            'teacher_price' => $teacherPrice,
            'platform_margin_percent' => 60,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        $booking = \App\Models\Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => $computed['student_price'],
            'teacher_amount' => $computed['provider_net'],
            'platform_amount' => $computed['platform_revenue'],
            'margin_percent_snapshot' => 60,
            'sessions_total' => $sessionsTotal,
            'sessions_remaining' => $sessionsTotal,
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->subHours(2),
            'duration_min' => 60,
            'status' => 'completed',
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'present',
        ]);
    }
}
