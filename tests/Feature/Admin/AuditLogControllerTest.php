<?php

namespace Tests\Feature\Admin;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_audit_log_entries(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $teacherUser = User::factory()->teacher()->create(['name' => 'أحمد المعلم']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        app(AuditLogService::class)->log('teacher.approved', $teacher, ['status' => 'pending'], ['status' => 'verified']);

        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson('/api/admin/audit-log');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.action', 'teacher.approved');
        $response->assertJsonPath('data.0.adminName', $admin->name);
        $response->assertJsonPath('data.0.targetLabel', 'أحمد المعلم');
        $this->assertNotNull($response->json('data.0.createdAt'));
    }

    public function test_audit_log_can_be_filtered_by_action(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        app(AuditLogService::class)->log('teacher.approved', $student);
        app(AuditLogService::class)->log('student.suspended', $student);

        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson('/api/admin/audit-log?action=student.suspended');

        $response->assertStatus(200);
        $entries = $response->json('data');
        $this->assertCount(1, $entries);
        $this->assertSame('student.suspended', $entries[0]['action']);
    }

    public function test_non_admin_cannot_view_audit_log(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson('/api/admin/audit-log');

        $response->assertStatus(403);
    }
}
