<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use App\Notifications\PasswordResetByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** يوازي Tests\Feature\Student\AdminResetStudentPasswordTest تماماً — راجعه لتفصيل الأنماط المشتركة */
class AdminResetTeacherPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_a_teachers_password(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create(['email' => 'reset.target@example.com', 'password' => 'OldPassword123!']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $existingToken = $teacherUser->createToken('session')->plainTextToken;

        $response = $this->as($adminToken)->putJson("/api/teachers/{$teacher->id}/password", [
            'password' => 'BrandNewPassword123!',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.password_reset_by_admin']);

        Notification::assertSentTo($teacherUser, PasswordResetByAdmin::class);

        // الجلسات القائمة أُبطلت
        $this->assertSame(0, $teacherUser->tokens()->count());
        $this->as($existingToken)->getJson('/api/auth/me')->assertStatus(401);

        // كلمة المرور الجديدة تعمل فعلياً
        $login = $this->postJson('/api/auth/login', ['email' => 'reset.target@example.com', 'password' => 'BrandNewPassword123!']);
        $login->assertStatus(200);
    }

    /**
     * معلم بحالة invited (مستورَد أو مدعو، بلا كلمة مرور بعد) — إن استخدم الأدمن
     * هذه الدالة لتفعيله مباشرة بدل انتظار قبوله للدعوة، يجب أن ينتقل لـ
     * active_unverified فوراً (يوازي أثر acceptInvitation)، وإلا يدخل المعلم
     * حسابه فيجد شاشة "أكمل ملفك الشخصي" تُخفي نموذج البيانات كاملاً (مقيَّد
     * بـ status === active_unverified حصراً في CompleteTeacherProfilePage)
     * فيعلق بلا أي طريق لمراجعة نبذته المستورَدة أو إرسال طلبه للمراجعة.
     */
    public function test_resetting_password_for_an_invited_teacher_activates_the_account(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create(['email' => 'invited.reset.target@example.com', 'password' => null]);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'invited']);

        $response = $this->as($adminToken)->putJson("/api/teachers/{$teacher->id}/password", [
            'password' => 'BrandNewPassword123!',
        ]);

        $response->assertStatus(200);
        $this->assertSame('active_unverified', $teacher->fresh()->status);
    }

    public function test_non_admin_cannot_reset_a_teachers_password(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $otherTeacherUser = User::factory()->teacher()->create();
        $otherToken = $otherTeacherUser->createToken('t')->plainTextToken;

        $response = $this->as($otherToken)->putJson("/api/teachers/{$teacher->id}/password", [
            'password' => 'BrandNewPassword123!',
        ]);

        $response->assertStatus(403);
    }

    public function test_reset_password_requires_a_valid_password(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $response = $this->as($adminToken)->putJson("/api/teachers/{$teacher->id}/password", [
            'password' => '123',
        ]);

        $response->assertStatus(422);
    }
}
