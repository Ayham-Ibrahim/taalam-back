<?php

namespace Tests\Feature\Teacher;

use App\Models\AccountInvitation;
use App\Models\Curriculum;
use App\Models\Language;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\TeacherInvited;
use App\Notifications\TeacherVerificationReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeacherInviteVerifyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_invite_to_verified_flow(): void
    {
        Notification::fake();
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $invite = $this->as($adminToken)->postJson('/api/teachers', [
            'name' => 'أستاذ أحمد',
            'email' => 'teacher@example.com',
            'teacher_type' => 'school',
        ]);

        $invite->assertStatus(201)->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('teachers', ['status' => 'invited', 'teacher_type' => 'school']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.invited']);

        $teacherUser = User::where('email', 'teacher@example.com')->firstOrFail();
        Notification::assertSentTo($teacherUser, TeacherInvited::class);
        $this->assertDatabaseHas('notification_logs', ['event' => 'teacher.invited']);

        $invitation = AccountInvitation::where('user_id', $teacherUser->id)->firstOrFail();

        $accept = $this->postJson('/api/teachers/accept-invitation', [
            'token' => $invitation->token,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $accept->assertStatus(200);
        $teacherToken = $accept->json('data.token');
        $this->assertDatabaseHas('teachers', ['user_id' => $teacherUser->id, 'status' => 'active_unverified']);

        $teacher = Teacher::where('user_id', $teacherUser->id)->firstOrFail();

        $tooEarly = $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification");
        $tooEarly->assertStatus(422);

        $subject = Subject::create(['code' => 'math', 'name_ar' => 'رياضيات', 'education_type' => 'school']);
        $curriculum = Curriculum::create(['code' => 'national', 'name_ar' => 'وطني']);
        $language = Language::create(['code' => 'ar', 'name_ar' => 'العربية']);

        $update = $this->as($teacherToken)->putJson("/api/teachers/{$teacher->id}", [
            'bio' => 'مدرس رياضيات بخبرة 5 سنوات',
            'subject_ids' => [$subject->id],
            'curriculum_ids' => [$curriculum->id],
            'language_ids' => [$language->id],
        ]);

        $update->assertStatus(200);
        $this->assertDatabaseHas('teacher_subject', ['teacher_id' => $teacher->id, 'subject_id' => $subject->id]);

        $file = UploadedFile::fake()->create('identity.pdf', 50, 'application/pdf');

        $upload = $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'identity',
            'file' => $file,
        ]);

        $upload->assertStatus(201);
        $documentId = $upload->json('data.id');
        $storedPath = $upload->json('data.s3_path');

        Storage::disk('local')->assertExists($storedPath);
        Storage::disk('public')->assertMissing($storedPath);

        // الهوية وحدها لا تكفي — التسجيل يتطلب أيضاً الشهادة الأكاديمية وشهادة الخبرة
        $stillMissing = $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification");
        $stillMissing->assertStatus(422);

        $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'academic',
            'file' => UploadedFile::fake()->create('academic.pdf', 50, 'application/pdf'),
        ])->assertStatus(201);

        $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'experience',
            'file' => UploadedFile::fake()->create('experience.pdf', 50, 'application/pdf'),
        ])->assertStatus(201);

        $submit = $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification");
        $submit->assertStatus(200);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'status' => 'pending_verification']);

        $approveDoc = $this->as($adminToken)->postJson("/api/verification-documents/{$documentId}/approve");
        $approveDoc->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.approved']);

        $forbidden = $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/approve");
        $forbidden->assertStatus(403);

        $approve = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve");
        $approve->assertStatus(200)->assertJsonPath('data.status', 'verified');

        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'status' => 'verified', 'verified_by' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.verified']);
        Notification::assertSentTo($teacherUser, TeacherVerificationReviewed::class);

        $approveAgain = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve");
        $approveAgain->assertStatus(422);

        $downloadUrl = $this->as($adminToken)->postJson("/api/verification-documents/{$documentId}/download-url");
        $downloadUrl->assertStatus(200);
        $url = $downloadUrl->json('data.url');
        $this->assertStringContainsString('signature=', $url);

        $download = $this->get($url);
        $download->assertStatus(200);
    }

    public function test_admin_can_reject_teacher_with_reason(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();

        $reject = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/reject", ['reason' => 'وثائق غير مكتملة']);

        $reject->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('teachers', [
            'id' => $teacher->id,
            'status' => 'rejected',
            'rejection_reason' => 'وثائق غير مكتملة',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.rejected']);
    }

    public function test_admin_can_suspend_verified_teacher(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve")->assertStatus(200);

        $suspend = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/suspend", ['reason' => 'شكوى متكررة']);

        $suspend->assertStatus(200)->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.suspended']);
    }

    public function test_teacher_cannot_invite_another_teacher(): void
    {
        [, $teacherToken] = $this->createTeacherReadyForReview();

        $response = $this->as($teacherToken)->postJson('/api/teachers', [
            'name' => 'آخر', 'email' => 'other@example.com', 'teacher_type' => 'school',
        ]);

        $response->assertStatus(403);
    }

    /**
     * @return array{0: Teacher, 1: string, 2: string}
     */
    private function createTeacherReadyForReview(): array
    {
        Notification::fake();
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $this->as($adminToken)->postJson('/api/teachers', [
            'name' => 'أستاذ خالد', 'email' => 'khaled@example.com', 'teacher_type' => 'school',
        ]);

        $teacherUser = User::where('email', 'khaled@example.com')->firstOrFail();
        $invitation = AccountInvitation::where('user_id', $teacherUser->id)->firstOrFail();

        $accept = $this->postJson('/api/teachers/accept-invitation', [
            'token' => $invitation->token,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
        $teacherToken = $accept->json('data.token');

        $teacher = Teacher::where('user_id', $teacherUser->id)->firstOrFail();

        $this->as($teacherToken)->putJson("/api/teachers/{$teacher->id}", ['bio' => 'نبذة عن المعلم']);

        foreach (['identity', 'academic', 'experience'] as $type) {
            $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
                'type' => $type,
                'file' => UploadedFile::fake()->create("{$type}.pdf", 50, 'application/pdf'),
            ]);
        }

        $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification");

        return [$teacher->fresh(), $teacherToken, $adminToken];
    }
}
