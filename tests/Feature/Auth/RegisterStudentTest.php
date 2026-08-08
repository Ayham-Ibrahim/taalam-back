<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegisterStudentTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_student_can_register(): void
    {
        $curriculumId = DB::table('curricula')->insertGetId([
            'code' => 'national', 'name_ar' => 'وطني', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stageId = DB::table('stages')->insertGetId([
            'code' => 'primary', 'name_ar' => 'ابتدائي', 'education_type' => 'school', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/register/student', [
            'name' => 'أحمد محمد',
            'email' => 'ahmad@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'education_type' => 'school',
            'curriculum_id' => $curriculumId,
            'stage_id' => $stageId,
            'grade' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'ahmad@example.com', 'role' => 'student']);
        $this->assertDatabaseHas('students', ['curriculum_id' => $curriculumId, 'stage_id' => $stageId]);
    }

    public function test_school_registration_requires_curriculum_and_stage(): void
    {
        $response = $this->postJson('/api/auth/register/student', [
            'name' => 'Test Student',
            'email' => 'noreqs@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'education_type' => 'school',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['curriculum_id', 'stage_id']);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/auth/register/student', [
            'name' => 'Test Student',
            'email' => 'dup@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'education_type' => 'training',
            'level' => 'beginner',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    /** Password::defaults() الموحّدة: 8 أحرف على الأقل + حرف كبير وصغير + رقم */
    public function test_registration_rejects_a_weak_password(): void
    {
        $response = $this->postJson('/api/auth/register/student', [
            'name' => 'Test Student',
            'email' => 'weakpass@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'education_type' => 'training',
            'level' => 'beginner',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertDatabaseMissing('users', ['email' => 'weakpass@example.com']);
    }
}
