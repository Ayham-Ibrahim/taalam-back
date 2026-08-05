<?php

namespace Tests\Feature\Teacher;

use App\Models\Badge;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeGrantRevokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_grant_and_revoke_badge(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $badge = Badge::create(['code' => 'top_rated', 'name_ar' => 'الأعلى تقييماً']);

        $grant = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/teachers/{$teacher->id}/badges", ['badge_id' => $badge->id]);

        $grant->assertStatus(201);
        $awardId = $grant->json('data.id');

        $this->assertDatabaseHas('badge_awards', ['id' => $awardId, 'teacher_id' => $teacher->id, 'badge_id' => $badge->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'badge.granted']);

        $revoke = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/badge-awards/{$awardId}/revoke", ['reason' => 'انتهاء الفترة']);

        $revoke->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'badge.revoked']);

        $revokeAgain = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/badge-awards/{$awardId}/revoke", ['reason' => 'مكرر']);
        $revokeAgain->assertStatus(422);
    }

    public function test_non_admin_cannot_grant_badge(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $badge = Badge::create(['code' => 'top_rated', 'name_ar' => 'الأعلى تقييماً']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/teachers/{$teacher->id}/badges", ['badge_id' => $badge->id]);

        $response->assertStatus(403);
    }
}
