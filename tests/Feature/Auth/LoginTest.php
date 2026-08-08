<?php

namespace Tests\Feature\Auth;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->admin()->create(['password' => 'Password123!']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->admin()->create(['password' => 'Password123!']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
    }

    /** يمنع تخمين كلمة المرور المتكرر — 5 محاولات/دقيقة لكل (إيميل+IP)، مهما كانت النتيجة (نجاح أو فشل) */
    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->admin()->create(['password' => 'Password123!']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $sixth = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $sixth->assertStatus(429);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->admin()->create([
            'password' => 'Password123!',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('test')->plainTextToken;

        $me = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/me');
        $me->assertStatus(200)->assertJsonPath('data.email', $user->email);

        $logout = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/auth/logout');
        $logout->assertStatus(200)->assertJsonPath('status', 'success');
    }

    public function test_unauthenticated_request_to_protected_route_is_rejected(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401)->assertJsonPath('status', 'error');
    }

    public function test_teacher_login_response_includes_teacher_type_without_a_separate_call(): void
    {
        $teacherUser = User::factory()->teacher()->create(['password' => 'Password123!']);
        Teacher::create([
            'user_id' => $teacherUser->id,
            'teacher_type' => 'training_center',
            'status' => 'verified',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $teacherUser->email,
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.teacher.teacher_type', 'training_center')
            ->assertJsonPath('data.user.teacher.status', 'verified')
            ->assertJsonMissingPath('data.user.student');
    }

    public function test_student_login_response_does_not_include_teacher_key(): void
    {
        $studentUser = User::factory()->student()->create(['password' => 'Password123!']);
        Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $studentUser->email,
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.student.education_type', 'school')
            ->assertJsonMissingPath('data.user.teacher');
    }
}
