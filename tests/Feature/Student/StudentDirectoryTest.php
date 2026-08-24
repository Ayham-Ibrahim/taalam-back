<?php

namespace Tests\Feature\Student;

use App\Models\Booking;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_students_by_name(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $studentUser = User::factory()->student()->create(['name' => 'نورة الشمري']);
        Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        $otherUser = User::factory()->student()->create(['name' => 'سالم أحمد']);
        Student::create(['user_id' => $otherUser->id, 'education_type' => 'school']);

        $response = $this->as($adminToken)->getJson('/api/students?search='.urlencode('نورة'));

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('نورة الشمري'));
        $this->assertFalse($names->contains('سالم أحمد'));
    }

    public function test_admin_can_filter_students_by_education_type(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $schoolUser = User::factory()->student()->create(['name' => 'طالب مدرسي']);
        Student::create(['user_id' => $schoolUser->id, 'education_type' => 'school']);

        $uniUser = User::factory()->student()->create(['name' => 'طالب جامعي']);
        Student::create(['user_id' => $uniUser->id, 'education_type' => 'university']);

        $response = $this->as($adminToken)->getJson('/api/students?education_type=university');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('طالب جامعي'));
        $this->assertFalse($names->contains('طالب مدرسي'));
    }

    public function test_non_admin_cannot_list_students(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacherToken = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($teacherToken)->getJson('/api/students');

        $response->assertStatus(403);
    }

    public function test_teacher_can_view_a_student_they_have_a_booking_with(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $student = $this->createStudentWithBooking($teacher);

        $response = $this->as($teacherToken)->getJson("/api/students/{$student->id}");

        $response->assertStatus(200);
    }

    public function test_teacher_cannot_view_a_student_they_have_no_relationship_with(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        [$otherTeacher] = $this->createVerifiedTeacher();
        $unrelatedStudent = $this->createStudentWithBooking($otherTeacher);

        $response = $this->as($teacherToken)->getJson("/api/students/{$unrelatedStudent->id}");

        $response->assertStatus(403);
    }

    public function test_student_can_view_their_own_profile_only(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $student = $this->createStudentWithBooking($teacher);
        $studentUser = User::find($student->user_id);
        $studentToken = $studentUser->createToken('t')->plainTextToken;

        $ownResponse = $this->as($studentToken)->getJson("/api/students/{$student->id}");
        $ownResponse->assertStatus(200);

        $otherUser = User::factory()->student()->create();
        $otherStudent = Student::create(['user_id' => $otherUser->id, 'education_type' => 'school']);

        $otherResponse = $this->as($studentToken)->getJson("/api/students/{$otherStudent->id}");
        $otherResponse->assertStatus(403);
    }

    public function test_student_profile_show_includes_ids_and_settings_only_fields(): void
    {
        $studentUser = User::factory()->student()->create(['whatsapp' => '0599999999', 'gender' => 'male']);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'education_type' => 'school',
            'guardian_name' => 'أبو الطالب',
            'guardian_phone' => '0588888888',
            'birth_date' => '2008-01-15',
        ]);
        $curriculum = \App\Models\Curriculum::create(['code' => 'sd-1', 'name_ar' => 'منهج']);
        $stage = \App\Models\Stage::create(['code' => 'sd-1', 'name_ar' => 'مرحلة', 'education_type' => 'school']);
        $student->update(['curriculum_id' => $curriculum->id, 'stage_id' => $stage->id]);
        $token = $studentUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson("/api/students/{$student->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertSame($curriculum->id, $data['curriculum_id']);
        $this->assertSame($stage->id, $data['stage_id']);
        $this->assertSame('0599999999', $data['whatsapp']);
        $this->assertSame('male', $data['gender']);
        $this->assertSame('أبو الطالب', $data['guardian_name']);
        $this->assertSame('0588888888', $data['guardian_phone']);
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $token = $teacherUser->createToken('t')->plainTextToken;

        return [$teacher, $token];
    }

    private function createStudentWithBooking(Teacher $teacher): Student
    {
        $subject = Subject::create(['code' => 'cs-'.uniqid(), 'name_ar' => 'مادة']);

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

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        Booking::create([
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

        return $student;
    }
}
