<?php

namespace Tests\Feature\Student;

use App\Models\Student;
use App\Models\User;
use App\Notifications\PasswordResetByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminResetStudentPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_a_students_password(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $studentUser = User::factory()->student()->create(['email' => 'reset.target@example.com', 'password' => 'OldPassword123!']);
        $student = Student::create(['user_id' => $studentUser->id]);
        $existingToken = $studentUser->createToken('session')->plainTextToken;

        $response = $this->as($adminToken)->putJson("/api/students/{$student->id}/password", [
            'password' => 'BrandNewPassword123!',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.password_reset_by_admin']);

        Notification::assertSentTo($studentUser, PasswordResetByAdmin::class);

        // الجلسات القائمة أُبطلت
        $this->assertSame(0, $studentUser->tokens()->count());
        $this->as($existingToken)->getJson('/api/auth/me')->assertStatus(401);

        // كلمة المرور الجديدة تعمل فعلياً
        $login = $this->postJson('/api/auth/login', ['email' => 'reset.target@example.com', 'password' => 'BrandNewPassword123!']);
        $login->assertStatus(200);
    }

    public function test_non_admin_cannot_reset_a_students_password(): void
    {
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id]);

        $otherStudentUser = User::factory()->student()->create();
        $otherToken = $otherStudentUser->createToken('t')->plainTextToken;

        $response = $this->as($otherToken)->putJson("/api/students/{$student->id}/password", [
            'password' => 'BrandNewPassword123!',
        ]);

        $response->assertStatus(403);
    }

    public function test_reset_password_requires_a_valid_password(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id]);

        $response = $this->as($adminToken)->putJson("/api/students/{$student->id}/password", [
            'password' => '123',
        ]);

        $response->assertStatus(422);
    }
}
