<?php

namespace Tests\Feature\Admin;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_overview_stats_and_pending_verifications(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $verifiedUser = User::factory()->teacher()->create();
        Teacher::create(['user_id' => $verifiedUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $pendingUser = User::factory()->teacher()->create(['name' => 'معلم قيد المراجعة']);
        Teacher::create(['user_id' => $pendingUser->id, 'teacher_type' => 'school', 'status' => 'pending_verification']);

        $response = $this->as($adminToken)->getJson('/api/dashboard/admin');

        $response->assertStatus(200);
        $response->assertJsonPath('data.stats.verifiedTeachersCount', 1);
        $response->assertJsonPath('data.stats.pendingVerificationsCount', 1);
        $this->assertCount(1, $response->json('data.pendingVerifications'));
        $response->assertJsonPath('data.pendingVerifications.0.name', 'معلم قيد المراجعة');
    }

    public function test_non_admin_cannot_view_overview(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson('/api/dashboard/admin');

        $response->assertStatus(403);
    }
}
