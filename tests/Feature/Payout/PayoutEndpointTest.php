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

    public function test_a_generated_payout_appears_in_the_unified_list_shape(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $this->generatePayout($teacher);

        $list = $this->withHeader('Authorization', "Bearer {$adminToken}")->getJson('/api/payouts');
        $list->assertStatus(200);
        $list->assertJsonPath('data.0.providerName', $teacherUser->name);
        $list->assertJsonPath('data.0.providerType', 'school');
        $list->assertJsonPath('data.0.sessionsCount', 1);
        $list->assertJsonPath('data.0.totalAmount', 100);
        $list->assertJsonPath('data.0.status', 'pending');
    }

    /** التوليد لمعلم واحد بفترة حرة أُزيل — الكشوف تُولَّد لكل المعلمين بزر واحد (payouts/generate) */
    public function test_per_teacher_payout_generation_endpoint_is_removed(): void
    {
        $admin = User::factory()->admin()->create();

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $this->as($admin->createToken('t')->plainTextToken)
            ->postJson("/api/teachers/{$teacher->id}/payouts/generate", [
                'period_start' => now()->subDay()->toDateString(),
                'period_end' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(404);

        $this->assertDatabaseCount('payouts', 0);
    }

    /** الكشف متاح بحالتيه — لا خطوة اعتماد قبله */
    public function test_invoice_is_available_for_an_unpaid_payout(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $payoutId = $this->generatePayout($teacher);

        $response = $this->as($adminToken)->get("/api/payouts/{$payoutId}/invoice");
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_teacher_can_download_their_own_payout_invoice_but_not_another_teachers(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $payoutId = $this->generatePayout($teacher);

        $this->as($teacherUser->createToken('t')->plainTextToken)
            ->get("/api/payouts/{$payoutId}/invoice")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        $otherTeacherUser = User::factory()->teacher()->create();
        Teacher::create(['user_id' => $otherTeacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $this->as($otherTeacherUser->createToken('t')->plainTextToken)
            ->getJson("/api/payouts/{$payoutId}/invoice")
            ->assertStatus(403);
    }

    public function test_admin_can_export_payouts_to_excel_but_a_teacher_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);
        $this->generatePayout($teacher);

        $response = $this->as($adminToken)->get('/api/payouts/export');
        $response->assertStatus(200);
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $this->as($teacherUser->createToken('t')->plainTextToken)
            ->getJson('/api/payouts/export')
            ->assertStatus(403);
    }

    /** عمود "المادة" في ملف الإكسل يعرض المواد التي أعطاها المعلم في فترة المستحقات */
    public function test_export_row_lists_the_subjects_the_teacher_taught_in_the_period(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $payout = app(\App\Services\PayoutService::class)
            ->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        $row = (new \App\Exports\PayoutsExport)->query()->where('id', $payout->id)->get()
            ->map(fn ($p) => (new \App\Exports\PayoutsExport)->map($p))
            ->first();

        $this->assertSame('مادة', $row[2]);
        $this->assertCount(13, $row);
    }

    public function test_payout_details_list_its_items_with_attendance_only_for_finance(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $payoutId = $this->generatePayout($teacher);

        $this->as($adminToken)->getJson("/api/payouts/{$payoutId}")
            ->assertStatus(200)
            ->assertJsonPath('data.providerName', $teacherUser->name)
            ->assertJsonPath('data.grossAmount', 100)
            ->assertJsonPath('data.totalAmount', 100)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.type', 'session')
            ->assertJsonPath('data.items.0.amount', 100)
            ->assertJsonPath('data.items.0.title', 'باقة')
            ->assertJsonPath('data.items.0.kind', 'individual')
            ->assertJsonPath('data.items.0.isMakeup', false)
            ->assertJsonPath('data.items.0.payoutPercent', null);

        $teacherItem = $this->as($teacherUser->createToken('t')->plainTextToken)
            ->getJson("/api/payouts/{$payoutId}")
            ->assertStatus(200)
            ->json('data.items.0');

        $this->assertArrayNotHasKey('attendanceOutcome', $teacherItem);
        $this->assertArrayNotHasKey('payoutPercent', $teacherItem);

        $otherTeacherUser = User::factory()->teacher()->create();
        Teacher::create(['user_id' => $otherTeacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $this->as($otherTeacherUser->createToken('t')->plainTextToken)
            ->getJson("/api/payouts/{$payoutId}")
            ->assertStatus(403);
    }

    public function test_mark_paid_requires_a_transfer_reference_and_succeeds_when_provided(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $payoutId = $this->generatePayout($teacher);

        // لا اعتماد — المسار أُزيل، والتحويل مباشرة من غير مدفوع
        $this->withHeader('Authorization', "Bearer {$adminToken}")->postJson("/api/payouts/{$payoutId}/approve")->assertStatus(404);

        $withoutReference = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/payouts/{$payoutId}/mark-paid", []);
        $withoutReference->assertStatus(422);

        $withReference = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/payouts/{$payoutId}/mark-paid", ['transfer_reference' => 'REF-123']);
        $withReference->assertStatus(200);
        $this->assertSame('paid', \App\Models\Payout::find($payoutId)->status);

        // مدفوع مرة واحدة فقط
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/payouts/{$payoutId}/mark-paid", ['transfer_reference' => 'REF-456'])
            ->assertStatus(422);
    }

    private function generatePayout(Teacher $teacher): int
    {
        return app(\App\Services\PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay())->id;
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
