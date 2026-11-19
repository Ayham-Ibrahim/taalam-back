<?php

namespace Tests\Feature\Student;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUpdateStudentAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_a_students_name_email_and_phone(): void
    {
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $studentUser = User::factory()->student()->create(['name' => 'Adam fares mahros', 'email' => 'old@example.com', 'phone' => '111']);
        $student = Student::create(['user_id' => $studentUser->id]);

        $response = $this->as($adminToken)->putJson("/api/students/{$student->id}/account", [
            'name' => 'Adam amr mahros',
            'email' => 'new@example.com',
            'phone' => '222',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.name', 'Adam amr mahros');
        $this->assertDatabaseHas('users', ['id' => $studentUser->id, 'name' => 'Adam amr mahros', 'email' => 'new@example.com', 'phone' => '222']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.account_updated_by_admin']);
    }

    public function test_email_and_phone_must_stay_unique(): void
    {
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;
        User::factory()->student()->create(['email' => 'taken@example.com', 'phone' => '999']);

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id]);

        $this->as($adminToken)->putJson("/api/students/{$student->id}/account", [
            'name' => 'X',
            'email' => 'taken@example.com',
            'phone' => '999',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);

        // بريده الحالي نفسه مقبول
        $this->as($adminToken)->putJson("/api/students/{$student->id}/account", [
            'name' => 'X',
            'email' => $studentUser->email,
        ])->assertStatus(200);
    }

    public function test_sibling_account_cannot_be_given_an_email(): void
    {
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $primary = User::factory()->student()->create();
        $sibling = User::factory()->student()->create(['email' => null, 'primary_user_id' => $primary->id]);
        $student = Student::create(['user_id' => $sibling->id]);

        $this->as($adminToken)->putJson("/api/students/{$student->id}/account", [
            'name' => 'Sib',
            'email' => 'sib@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->as($adminToken)->putJson("/api/students/{$student->id}/account", ['name' => 'Sib'])->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $sibling->id, 'name' => 'Sib', 'email' => null]);
    }

    public function test_non_admin_cannot_update_a_students_account(): void
    {
        $student = Student::create(['user_id' => User::factory()->student()->create()->id]);
        $token = User::factory()->student()->create()->createToken('t')->plainTextToken;

        $this->as($token)->putJson("/api/students/{$student->id}/account", ['name' => 'X', 'email' => 'x@example.com'])
            ->assertStatus(403);
    }
}
