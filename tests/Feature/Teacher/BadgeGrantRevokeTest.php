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

    /** الشارات الممنوحة (غير الملغاة) فقط تظهر بالبروفايل العام — لا الملغاة */
    public function test_only_active_badges_appear_on_the_public_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $activeBadge = Badge::create(['code' => 'featured', 'name_ar' => 'معلم مميز', 'icon' => '🥇']);
        $revokedBadge = Badge::create(['code' => 'accredited_center', 'name_ar' => 'مركز معتمد', 'icon' => '🏅']);

        $grantActive = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/teachers/{$teacher->id}/badges", ['badge_id' => $activeBadge->id]);
        $grantActive->assertStatus(201);

        $grantRevoked = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/teachers/{$teacher->id}/badges", ['badge_id' => $revokedBadge->id]);
        $revokedAwardId = $grantRevoked->json('data.id');

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/badge-awards/{$revokedAwardId}/revoke")
            ->assertStatus(200);

        // حارس Sanctum يخزّن المستخدم المُحلَّل مؤقتاً طوال عمر نسخة الحارس (نفس
        // ملاحظة TestCase::as())؛ مجرّد حذف ترويسة Authorization لا يكفي — يجب
        // نسيان الحراس صراحةً وإلا بقي هذا الطلب "يرى" أدمن نداءي المنح/الإلغاء
        // أعلاه فيُعامله show() كمدير ويرجّع النموذج الخام لا المورد العام.
        $this->app['auth']->forgetGuards();
        $response = $this->withoutHeader('Authorization')->getJson("/api/teachers/{$teacher->id}");
        $response->assertStatus(200)->assertJsonCount(1, 'data.badges');
        $this->assertSame('featured', $response->json('data.badges.0.code'));
    }
}
