<?php

namespace Tests\Feature\Teacher;

use App\Models\Booking;
use App\Models\Package;
use App\Models\Review;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_show_endpoint_includes_computed_stats(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();
        $package = $this->createActivePackage($teacher);
        $studentA = $this->createStudent();
        $studentB = $this->createStudent();

        $bookingA = $this->createBooking($teacher, $package, $studentA);
        $this->createBooking($teacher, $package, $studentB);

        // جلسة مكتملة مدتها 60 دقيقة + جلسة غير مكتملة لا تُحتسب
        $bookingA->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->subDay(),
            'duration_min' => 60,
            'status' => 'completed',
        ]);
        $bookingA->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 2,
            'scheduled_at' => now()->addDay(),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        Review::create([
            'student_id' => $studentA->id,
            'teacher_id' => $teacher->id,
            'rating' => 4,
        ]);

        $response = $this->as($token)->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.stats.total_students', 2);
        $this->assertEquals(1.0, $response->json('data.stats.teaching_hours'));
        $response->assertJsonPath('data.stats.reviews_count', 1);
        $this->assertEquals(4.0, $response->json('data.stats.rating_avg'));
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

    private function createActivePackage(Teacher $teacher): Package
    {
        $subject = Subject::create(['code' => 'cs-'.uniqid(), 'name_ar' => 'مادة']);

        return Package::create([
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
    }

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create();

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function createBooking(Teacher $teacher, Package $package, Student $student): Booking
    {
        return Booking::create([
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
    }
}
