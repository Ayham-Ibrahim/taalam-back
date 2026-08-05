<?php

namespace Tests\Feature\Teacher;

use App\Models\Curriculum;
use App\Models\Language;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\AccountCreatedByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCreatesTeacherAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_teacher_account_with_password_and_teacher_logs_in_immediately(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $create = $this->as($adminToken)->postJson('/api/teachers/create-account', [
            'name' => 'أستاذ سالم',
            'email' => 'salem@example.com',
            'teacher_type' => 'school',
            'password' => 'Password123!',
        ]);

        $create->assertStatus(201);
        $this->assertDatabaseHas('teachers', ['status' => 'active_unverified', 'teacher_type' => 'school']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.account_created']);
        $this->assertDatabaseHas('users', ['email' => 'salem@example.com', 'is_active' => true]);

        $teacherUser = User::where('email', 'salem@example.com')->firstOrFail();
        Notification::assertSentTo($teacherUser, AccountCreatedByAdmin::class);
        // مسار database غير مستخدَم عمداً — كلمة المرور نص صريح، لا تُخزَّن في notifications الدائمة
        $this->assertSame(0, $teacherUser->notifications()->count());
        $this->assertDatabaseHas('notification_logs', ['event' => 'teacher.account_created', 'channel' => 'email']);

        // لا خطوة قبول دعوة — الدخول فوري بنفس كلمة المرور التي وضعها الأدمن
        $login = $this->postJson('/api/auth/login', ['email' => 'salem@example.com', 'password' => 'Password123!']);
        $login->assertStatus(200);
        $login->assertJsonPath('data.user.teacher.status', 'active_unverified');
        $teacherToken = $login->json('data.token');

        $teacher = Teacher::where('user_id', $teacherUser->id)->firstOrFail();

        $subject = Subject::create(['code' => 'math', 'name_ar' => 'رياضيات', 'education_type' => 'school']);
        $curriculum = Curriculum::create(['code' => 'national', 'name_ar' => 'وطني']);
        $language = Language::create(['code' => 'ar', 'name_ar' => 'العربية']);

        $this->as($teacherToken)->putJson("/api/teachers/{$teacher->id}", [
            'bio' => 'مدرس رياضيات',
            'subject_ids' => [$subject->id],
            'curriculum_ids' => [$curriculum->id],
            'language_ids' => [$language->id],
        ])->assertStatus(200);

        Storage::fake('local');
        foreach (['identity', 'academic', 'experience'] as $type) {
            $this->as($teacherToken)->post("/api/teachers/{$teacher->id}/verification-documents", [
                'type' => $type,
                'file' => UploadedFile::fake()->create("{$type}.pdf", 50, 'application/pdf'),
            ])->assertStatus(201);
        }

        $submit = $this->as($teacherToken)->postJson("/api/teachers/{$teacher->id}/submit-for-verification");
        $submit->assertStatus(200);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'status' => 'pending_verification']);
    }

    public function test_non_admin_cannot_create_a_teacher_account(): void
    {
        $studentUser = User::factory()->student()->create();
        $token = $studentUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->postJson('/api/teachers/create-account', [
            'name' => 'أستاذ', 'email' => 'x@example.com', 'teacher_type' => 'school', 'password' => 'Password123!',
        ]);

        $response->assertStatus(403);
    }
}
