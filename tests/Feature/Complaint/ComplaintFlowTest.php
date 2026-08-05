<?php

namespace Tests\Feature\Complaint;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_file_a_complaint(): void
    {
        [$student, $studentToken] = $this->createStudent();

        $response = $this->as($studentToken)->postJson('/api/complaints', [
            'category' => 'session_quality',
            'description' => 'جودة الجلسة كانت سيئة',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('complaints', ['student_id' => $student->id, 'status' => 'open']);
    }

    public function test_resolution_type_is_restricted_to_non_refund_options(): void
    {
        [, $studentToken] = $this->createStudent();
        [, $adminToken] = $this->createAdmin();

        $file = $this->as($studentToken)->postJson('/api/complaints', [
            'category' => 'other',
            'description' => 'شكوى عامة',
        ]);
        $complaintId = $file->json('data.id');

        $invalid = $this->as($adminToken)->postJson("/api/complaints/{$complaintId}/resolve", [
            'resolution_type' => 'refund_full',
        ]);
        $invalid->assertStatus(422)->assertJsonValidationErrors(['resolution_type']);

        $valid = $this->as($adminToken)->postJson("/api/complaints/{$complaintId}/resolve", [
            'resolution_type' => 'credit',
            'notes' => 'تم منح رصيد إضافي',
        ]);
        $valid->assertStatus(200)->assertJsonPath('data.status', 'resolved');
        $this->assertDatabaseHas('audit_logs', ['action' => 'complaint.resolved']);
    }

    public function test_resolving_with_makeup_session_creates_a_new_session(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$student, $studentToken] = $this->createStudent();
        [, $adminToken] = $this->createAdmin();

        [$booking, $session] = $this->createBookingWithSession($teacher, $student);

        $file = $this->as($studentToken)->postJson('/api/complaints', [
            'category' => 'teacher_no_show',
            'description' => 'المعلم لم يحضر',
            'class_session_id' => $session->id,
        ]);
        $complaintId = $file->json('data.id');

        $resolve = $this->as($adminToken)->postJson("/api/complaints/{$complaintId}/resolve", [
            'resolution_type' => 'makeup_session',
        ]);
        $resolve->assertStatus(200);

        $this->assertDatabaseHas('class_sessions', [
            'makeup_for_session_id' => $session->id,
            'is_makeup' => 1,
        ]);
    }

    public function test_non_admin_cannot_resolve_complaint(): void
    {
        [, $studentToken] = $this->createStudent();

        $file = $this->as($studentToken)->postJson('/api/complaints', [
            'category' => 'other',
            'description' => 'شكوى',
        ]);
        $complaintId = $file->json('data.id');

        $response = $this->as($studentToken)->postJson("/api/complaints/{$complaintId}/resolve", [
            'resolution_type' => 'credit',
        ]);
        $response->assertStatus(403);
    }

    /**
     * @return array{0: Student, 1: string}
     */
    private function createStudent(): array
    {
        $user = User::factory()->student()->create();
        $student = Student::create(['user_id' => $user->id, 'education_type' => 'school']);

        return [$student, $user->createToken('t')->plainTextToken];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createAdmin(): array
    {
        $admin = User::factory()->admin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }

    /**
     * @return array{0: Teacher}
     */
    private function createVerifiedTeacher(): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher];
    }

    /**
     * @return array{0: Booking, 1: ClassSession}
     */
    private function createBookingWithSession(Teacher $teacher, Student $student): array
    {
        $subject = Subject::create(['code' => 'cx-'.uniqid(), 'name_ar' => 'مادة']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->subHour(),
            'duration_min' => 60,
            'status' => 'no_show_teacher',
        ]);

        return [$booking, $session];
    }
}
