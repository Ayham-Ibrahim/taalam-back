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
use App\Notifications\VerificationDocumentRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $academicUpload = $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'academic',
            'file' => UploadedFile::fake()->create('academic.pdf', 50, 'application/pdf'),
        ]);
        $academicUpload->assertStatus(201);

        $experienceUpload = $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'experience',
            'file' => UploadedFile::fake()->create('experience.pdf', 50, 'application/pdf'),
        ]);
        $experienceUpload->assertStatus(201);

        $submit = $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification");
        $submit->assertStatus(200);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'status' => 'pending_verification']);

        $approveDoc = $this->as($adminToken)->postJson("/api/verification-documents/{$documentId}/approve");
        $approveDoc->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.approved']);

        $forbidden = $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/approve");
        $forbidden->assertStatus(403);

        // الهوية وحدها معتمَدة حتى الآن — لا يمكن اعتماد المعلم قبل الموافقة
        // على الشهادة الأكاديمية وشهادة الخبرة أيضاً.
        $tooEarlyApprove = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve");
        $tooEarlyApprove->assertStatus(422);

        $this->as($adminToken)->postJson("/api/verification-documents/{$academicUpload->json('data.id')}/approve")->assertStatus(200);
        $this->as($adminToken)->postJson("/api/verification-documents/{$experienceUpload->json('data.id')}/approve")->assertStatus(200);

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

    /** الرفض إجراء عقابي — يجب أن يقطع أي جلسة نشطة للمعلم فوراً */
    public function test_rejecting_a_teacher_revokes_their_active_tokens(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $teacher->user_id]);

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/reject", ['reason' => 'وثائق غير واضحة'])->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $teacher->user_id]);
    }

    /**
     * الحذف متاح فقط لمعلم status='rejected' تحديداً (قرار عمل): لم يُوثَّق قط
     * فلا يملك حجوزات/مدفوعات حقيقية أصلاً — يحذف المستخدم والمعلم ووثائقه
     * (سجلات وملفات) نهائياً، لا مجرد تعليق soft delete.
     */
    public function test_admin_can_delete_a_rejected_teacher_and_all_their_data(): void
    {
        [$teacher, $teacherToken, $adminToken] = $this->createTeacherReadyForReview();
        $teacherUserId = $teacher->user_id;

        $document = $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'professional',
            'file' => UploadedFile::fake()->create('extra.pdf', 50, 'application/pdf'),
        ])->json('data');
        $storedPath = $document['s3_path'];
        Storage::disk('local')->assertExists($storedPath);

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/reject", ['reason' => 'وثائق غير واضحة'])->assertStatus(200);

        $response = $this->as($adminToken)->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('teachers', ['id' => $teacher->id]);
        $this->assertDatabaseMissing('users', ['id' => $teacherUserId]);
        $this->assertDatabaseMissing('verification_documents', ['teacher_id' => $teacher->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.deleted']);
        Storage::disk('local')->assertMissing($storedPath);
    }

    #[DataProvider('nonRejectedStatusProvider')]
    public function test_cannot_delete_a_teacher_who_is_not_rejected(string $status): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();
        $teacher->update(['status' => $status]);

        $response = $this->as($adminToken)->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id]);
    }

    public static function nonRejectedStatusProvider(): array
    {
        return [
            'verified' => ['verified'],
            'pending_verification' => ['pending_verification'],
            'suspended' => ['suspended'],
            'active_unverified' => ['active_unverified'],
        ];
    }

    public function test_non_admin_cannot_delete_a_teacher(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();
        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/reject", ['reason' => 'وثائق غير واضحة'])->assertStatus(200);

        // reject() تُلغي توكن المعلم القديم فوراً — نمنحه توكن جديداً هنا لاختبار
        // التفويض تحديداً (403)، لا صلاحية الجلسة (401).
        $teacherToken = User::find($teacher->user_id)->createToken('t2')->plainTextToken;

        $response = $this->as($teacherToken)->deleteJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id]);
    }

    public function test_admin_can_suspend_verified_teacher(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve")->assertStatus(200);

        $suspend = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/suspend", ['reason' => 'شكوى متكررة']);

        $suspend->assertStatus(200)->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.suspended']);
    }

    /** التعليق إجراء عقابي — يجب أن يقطع أي جلسة نشطة للمعلم فوراً */
    public function test_suspending_a_teacher_revokes_their_active_tokens(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();
        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve")->assertStatus(200);

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $teacher->user_id]);

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/suspend", ['reason' => 'شكوى متكررة'])->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $teacher->user_id]);
    }

    /**
     * كان زر "إعادة تفعيل" في الفرونت إند يستدعي endpoint التوثيق (approve)
     * خطأً — يشترط status='pending_verification' حصراً، فيُرفض دائماً لمعلم
     * suspended فعلياً برسالة "يمكن التوثيق فقط من حالة قيد المراجعة"، فلا
     * يمكن أبداً إعادة تفعيل معلم مُعلَّق سابقاً كان موثَّقاً. reactivate الآن
     * مسار مستقل يتحقق من status='suspended' تحديداً ويعيده إلى verified.
     */
    public function test_admin_can_reactivate_a_suspended_teacher(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve")->assertStatus(200);
        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/suspend", ['reason' => 'شكوى متكررة'])->assertStatus(200);

        $reactivate = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/reactivate");

        $reactivate->assertStatus(200)->assertJsonPath('data.status', 'verified');
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'status' => 'verified']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.reactivated']);
    }

    public function test_reactivate_rejects_a_teacher_who_is_not_currently_suspended(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve")->assertStatus(200);

        $reactivate = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/reactivate");

        $reactivate->assertStatus(422);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'status' => 'verified']);
    }

    public function test_non_admin_cannot_reactivate_a_teacher(): void
    {
        [$teacher, , $adminToken] = $this->createTeacherReadyForReview();

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve")->assertStatus(200);
        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/suspend", ['reason' => 'شكوى متكررة'])->assertStatus(200);

        // suspend() تُلغي توكن المعلم القديم فوراً — نمنحه توكن جديداً هنا
        // لاختبار التفويض تحديداً (403)، لا صلاحية الجلسة (401).
        $teacherToken = User::find($teacher->user_id)->createToken('t2')->plainTextToken;

        $response = $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/reactivate");

        $response->assertStatus(403);
    }

    /**
     * كان يمكن اعتماد المعلم بمجرد وصوله لحالة pending_verification حتى لو
     * لم يُوافَق على أي من وثائقه الثبوتية فعلياً بعد — الرفع وحده لا يعني
     * المراجعة. رسالة الخطأ يجب أن تكون واضحة تحديداً (لا رسالة عامة).
     */
    public function test_teacher_cannot_be_approved_before_all_required_documents_are_approved(): void
    {
        Notification::fake();
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;
        $teacherUser = User::factory()->teacher()->create();
        $teacherToken = $teacherUser->createToken('t')->plainTextToken;
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'active_unverified']);

        $this->as($teacherToken)->putJson("/api/teachers/{$teacher->id}", ['bio' => 'نبذة']);
        foreach (['identity', 'academic', 'experience'] as $type) {
            $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
                'type' => $type,
                'file' => UploadedFile::fake()->create("{$type}.pdf", 50, 'application/pdf'),
            ]);
        }
        $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification")->assertStatus(200);

        $response = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve");

        $response->assertStatus(422)
            ->assertJsonPath('errors.documents.0', 'لا يمكن اعتماد المعلم قبل الموافقة على جميع الوثائق الثبوتية المطلوبة.');
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'status' => 'pending_verification']);
    }

    /**
     * قد يرفع المعلم وثيقة جديدة لنفس النوع بعد رفض سابق (انظر الاختبار
     * التالي) — يجب أن تُعتمَد آخر وثيقة من كل نوع فقط، لا وثيقة قديمة مرفوضة
     * ما زالت موجودة في السجل التاريخي.
     */
    public function test_approval_considers_only_the_latest_document_per_type_not_an_older_rejected_one(): void
    {
        [$teacher, $teacherToken, $adminToken] = $this->createTeacherReadyForReviewWithoutApprovingDocuments();

        $oldIdentity = $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'identity',
            'file' => UploadedFile::fake()->create('identity-old.pdf', 50, 'application/pdf'),
        ])->json('data.id');
        $this->as($adminToken)->postJson("/api/verification-documents/{$oldIdentity}/reject", ['reason' => 'صورة غير واضحة']);

        $newIdentity = $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'identity',
            'file' => UploadedFile::fake()->create('identity-new.pdf', 50, 'application/pdf'),
        ])->json('data.id');
        $this->as($adminToken)->postJson("/api/verification-documents/{$newIdentity}/approve");

        $this->approveAllDocumentsOfType($teacher, $adminToken, ['academic', 'experience']);

        $response = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve");

        $response->assertStatus(200)->assertJsonPath('data.status', 'verified');
    }

    /**
     * السيناريو الكامل المطلوب: رفض وثيقة → إشعار للمعلم (بريد + إشعار لوحة
     * التحكم) بالوثيقة المرفوضة تحديداً → يعيد المعلم رفعها → يوافق الأدمن
     * عليها → يمكن الاعتماد الآن. وينطبق تماماً على إعادة تفعيل معلم موقوف.
     */
    public function test_rejecting_a_document_notifies_the_teacher_and_reupload_then_approval_unblocks_reactivation(): void
    {
        [$teacher, $teacherToken, $adminToken] = $this->createTeacherReadyForReview();
        $teacherUser = User::find($teacher->user_id);

        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/approve")->assertStatus(200);
        $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/suspend", ['reason' => 'شكوى متكررة'])->assertStatus(200);

        // أثناء التعليق، تبيّن أن وثيقة الخبرة كانت غير صحيحة فرُفضت مجدداً
        $experienceDoc = $teacher->verificationDocuments()->where('type', 'experience')->firstOrFail();
        $reject = $this->as($adminToken)->postJson("/api/verification-documents/{$experienceDoc->id}/reject", ['reason' => 'الوثيقة منتهية الصلاحية']);
        $reject->assertStatus(200);

        Notification::assertSentTo($teacherUser, VerificationDocumentRejected::class);
        $this->assertDatabaseHas('notification_logs', ['event' => 'document.rejected']);

        // إعادة التفعيل مرفوضة الآن — وثيقة الخبرة المعتمَدة سابقاً استُبدلت برفض جديد
        $tooEarly = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/reactivate");
        $tooEarly->assertStatus(422)
            ->assertJsonPath('errors.documents.0', 'لا يمكن اعتماد المعلم قبل الموافقة على جميع الوثائق الثبوتية المطلوبة.');

        // suspend() ألغت توكن المعلم القديم — يسجّل الدخول من جديد ليتفاعل مع حسابه
        $teacherToken = $teacherUser->createToken('t2')->plainTextToken;

        // المعلم يفتح حسابه، يرى الرفض، ويعيد رفع الوثيقة
        $reupload = $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
            'type' => 'experience',
            'file' => UploadedFile::fake()->create('experience-v2.pdf', 50, 'application/pdf'),
        ]);
        $reupload->assertStatus(201);

        // الأدمن يقبل الوثيقة الجديدة ثم يعيد تفعيل المعلم
        $this->as($adminToken)->postJson("/api/verification-documents/{$reupload->json('data.id')}/approve")->assertStatus(200);

        $reactivate = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/reactivate");
        $reactivate->assertStatus(200)->assertJsonPath('data.status', 'verified');
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
            $upload = $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
                'type' => $type,
                'file' => UploadedFile::fake()->create("{$type}.pdf", 50, 'application/pdf'),
            ]);
            $this->as($adminToken)->postJson("/api/verification-documents/{$upload->json('data.id')}/approve");
        }

        $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification");

        return [$teacher->fresh(), $teacherToken, $adminToken];
    }

    /**
     * @return array{0: Teacher, 1: string, 2: string}
     */
    private function createTeacherReadyForReviewWithoutApprovingDocuments(): array
    {
        Notification::fake();
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;
        $teacherUser = User::factory()->teacher()->create();
        $teacherToken = $teacherUser->createToken('t')->plainTextToken;
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'active_unverified']);

        $this->as($teacherToken)->putJson("/api/teachers/{$teacher->id}", ['bio' => 'نبذة']);
        foreach (['identity', 'academic', 'experience'] as $type) {
            $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
                'type' => $type,
                'file' => UploadedFile::fake()->create("{$type}.pdf", 50, 'application/pdf'),
            ]);
        }
        $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification")->assertStatus(200);

        return [$teacher->fresh(), $teacherToken, $adminToken];
    }

    private function approveAllDocumentsOfType(Teacher $teacher, string $adminToken, array $types): void
    {
        foreach ($types as $type) {
            $document = $teacher->verificationDocuments()->where('type', $type)->latest()->firstOrFail();
            $this->as($adminToken)->postJson("/api/verification-documents/{$document->id}/approve")->assertStatus(200);
        }
    }
}
