<?php

namespace Tests\Feature\Student;

use App\Models\Curriculum;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AccountCreatedByAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminCreatesStudentAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_student_account_and_student_completes_profile_after_first_login(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $create = $this->as($adminToken)->postJson('/api/students/create-account', [
            'name' => 'سارة أحمد',
            'email' => 'sara@example.com',
            'password' => 'Password123!',
        ]);

        $create->assertStatus(201);
        $this->assertDatabaseHas('students', ['education_type' => null]);
        $this->assertDatabaseHas('users', ['email' => 'sara@example.com', 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.account_created']);

        $studentUser = User::where('email', 'sara@example.com')->firstOrFail();
        Notification::assertSentTo($studentUser, AccountCreatedByAdmin::class);
        $this->assertSame(0, $studentUser->notifications()->count());

        $login = $this->postJson('/api/auth/login', ['email' => 'sara@example.com', 'password' => 'Password123!']);
        $login->assertStatus(200);
        $this->assertNull($login->json('data.user.student.education_type'));
        $studentToken = $login->json('data.token');

        $student = Student::where('user_id', $studentUser->id)->firstOrFail();

        $curriculum = Curriculum::create(['code' => 'national', 'name_ar' => 'وطني']);
        $stage = Stage::create(['code' => 'primary', 'name_ar' => 'ابتدائي']);

        $incomplete = $this->as($studentToken)->putJson("/api/students/{$student->id}", [
            'education_type' => 'school',
        ]);
        $incomplete->assertStatus(422);

        $complete = $this->as($studentToken)->putJson("/api/students/{$student->id}", [
            'education_type' => 'school',
            'curriculum_id' => $curriculum->id,
            'stage_id' => $stage->id,
            'grade' => 6,
            'guardian_name' => 'أبو سارة',
            'guardian_phone' => '0590000000',
        ]);
        $complete->assertStatus(200)->assertJsonPath('data.education_type', 'school');
        $this->assertDatabaseHas('students', ['id' => $student->id, 'education_type' => 'school', 'grade' => 6]);
    }

    public function test_a_student_cannot_complete_another_students_profile(): void
    {
        $owner = User::factory()->student()->create();
        $ownerStudent = Student::create(['user_id' => $owner->id]);

        $intruder = User::factory()->student()->create();
        $intruderToken = $intruder->createToken('t')->plainTextToken;

        $response = $this->as($intruderToken)->putJson("/api/students/{$ownerStudent->id}", ['education_type' => 'school']);

        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_create_a_student_account(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->postJson('/api/students/create-account', [
            'name' => 'طالب', 'email' => 'y@example.com', 'password' => 'Password123!',
        ]);

        $response->assertStatus(403);
    }
}
