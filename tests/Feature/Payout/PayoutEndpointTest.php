<?php

namespace Tests\Feature\Payout;

use App\Models\Booking;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_a_payout_via_http_with_a_period_and_sees_the_unified_list_shape(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $generate = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            "/api/teachers/{$teacher->id}/payouts/generate",
            ['period_start' => now()->subDay()->toDateString(), 'period_end' => now()->addDay()->toDateString()],
        );

        $generate->assertStatus(201);

        $list = $this->withHeader('Authorization', "Bearer {$adminToken}")->getJson('/api/payouts');
        $list->assertStatus(200);
        $list->assertJsonPath('data.0.providerName', $teacherUser->name);
        $list->assertJsonPath('data.0.providerType', 'school');
        $list->assertJsonPath('data.0.sessionsCount', 1);
        $list->assertJsonPath('data.0.totalAmount', 100);
        $list->assertJsonPath('data.0.status', 'pending');
    }

    public function test_generate_payout_without_a_period_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/teachers/{$teacher->id}/payouts/generate", []);

        $response->assertStatus(422);
    }

    public function test_mark_paid_requires_a_transfer_reference_and_succeeds_when_provided(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $generate = $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson(
            "/api/teachers/{$teacher->id}/payouts/generate",
            ['period_start' => now()->subDay()->toDateString(), 'period_end' => now()->addDay()->toDateString()],
        );
        $payoutId = $generate->json('data.id');

        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson("/api/payouts/{$payoutId}/approve")->assertStatus(200);

        $withoutReference = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/payouts/{$payoutId}/mark-paid", []);
        $withoutReference->assertStatus(422);

        $withReference = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/payouts/{$payoutId}/mark-paid", ['transfer_reference' => 'REF-123']);
        $withReference->assertStatus(200);
    }

    private function createCompletedPackageSession(Teacher $teacher, float $teacherPrice, int $sessionsTotal): void
    {
        $subject = Subject::create(['code' => 'po-'.uniqid(), 'name_ar' => 'مادة']);

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

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => $computed['student_price'],
            'teacher_amount' => $computed['provider_total'],
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
