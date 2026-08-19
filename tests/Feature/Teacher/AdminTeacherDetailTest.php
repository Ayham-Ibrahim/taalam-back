<?php

namespace Tests\Feature\Teacher;

use App\Models\Badge;
use App\Models\BadgeAward;
use App\Models\Curriculum;
use App\Models\Language;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTeacherDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_unified_teacher_documents_and_badges_shape(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'teacher_type' => 'school',
            'status' => 'pending_verification',
            'bio' => 'معلم متمرس',
            'qualification' => 'master',
            'experience_years' => '3_5',
        ]);

        $subject = Subject::create(['code' => 'math-'.uniqid(), 'name_ar' => 'رياضيات']);
        $curriculum = Curriculum::create(['code' => 'nat-'.uniqid(), 'name_ar' => 'وطني']);
        $language = Language::create(['code' => 'ar', 'name_ar' => 'العربية']);
        $teacher->subjects()->attach($subject->id);
        $teacher->curricula()->attach($curriculum->id);
        $teacher->languages()->attach($language->id);

        VerificationDocument::create([
            'teacher_id' => $teacher->id,
            'type' => 'identity',
            's3_path' => 'docs/identity.pdf',
            'original_name' => 'identity.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'status' => 'pending',
        ]);

        $activeBadge = Badge::create(['code' => 'top_rated', 'name_ar' => 'الأعلى تقييماً', 'icon' => '🥇', 'sort_order' => 1]);
        $revokedBadge = Badge::create(['code' => 'featured', 'name_ar' => 'مميز', 'icon' => '⭐', 'sort_order' => 2]);

        BadgeAward::create([
            'teacher_id' => $teacher->id,
            'badge_id' => $activeBadge->id,
            'granted_by' => $admin->id,
            'granted_at' => now(),
        ]);
        BadgeAward::create([
            'teacher_id' => $teacher->id,
            'badge_id' => $revokedBadge->id,
            'granted_by' => $admin->id,
            'granted_at' => now(),
            'revoked_at' => now(),
            'revoked_by' => $admin->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson("/api/teachers/{$teacher->id}/admin-detail");

        $response->assertStatus(200);
        $response->assertJsonPath('data.teacher.id', $teacher->id);
        $response->assertJsonPath('data.teacher.name', $teacherUser->name);
        $response->assertJsonPath('data.teacher.status', 'pending');
        $response->assertJsonPath('data.teacher.rawStatus', 'pending_verification');
        $response->assertJsonPath('data.teacher.canApprove', true);
        $response->assertJsonPath('data.teacher.approvalBlockedReason', null);
        $response->assertJsonPath('data.teacher.bio', 'معلم متمرس');
        $response->assertJsonPath('data.teacher.qualification', 'master');
        $response->assertJsonPath('data.teacher.experienceYears', '3_5');
        $response->assertJsonPath('data.teacher.subjects.0.name', 'رياضيات');
        $response->assertJsonPath('data.teacher.curricula.0.name', 'وطني');
        $response->assertJsonPath('data.teacher.languages.0.name', 'العربية');

        $response->assertJsonPath('data.documents.0.type', 'identity');
        $response->assertJsonPath('data.documents.0.status', 'pending');
        $response->assertJsonPath('data.documents.0.fileName', 'identity.pdf');

        $awards = $response->json('data.badgeAwards');
        $this->assertCount(1, $awards);
        $this->assertSame($activeBadge->id, $awards[0]['badgeId']);
        $this->assertSame('الأعلى تقييماً', $awards[0]['badge']['name']);

        $catalog = $response->json('data.badgeCatalog');
        $this->assertCount(2, $catalog);
    }

    public function test_teacher_can_view_their_own_admin_detail(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'active_unverified']);
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/teachers/{$teacher->id}/admin-detail");

        $response->assertStatus(200);
        $response->assertJsonPath('data.teacher.status', 'pending');
        $response->assertJsonPath('data.teacher.rawStatus', 'active_unverified');
        $response->assertJsonPath('data.teacher.canApprove', false);
        $response->assertJsonPath('data.teacher.approvalBlockedReason', 'لم يُكمل المعلم ملفه الشخصي أو يرسل طلب التوثيق بعد، لذلك لا يمكن اعتماده الآن.');
    }

    public function test_student_cannot_view_a_teachers_admin_detail(): void
    {
        $studentUser = User::factory()->student()->create();
        $token = $studentUser->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/teachers/{$teacher->id}/admin-detail");

        $response->assertStatus(403);
    }
}
