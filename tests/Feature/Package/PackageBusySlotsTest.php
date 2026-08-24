<?php

namespace Tests\Feature\Package;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "الوقت المتعارض يجب أن يظهر كـ غير متاح في الواجهة" — هذا الـ endpoint هو
 * ما يغذّي ذلك العرض المسبق قبل الإرسال (ليس الفحص الملزم، ذاك يبقى في
 * ScheduleConflictService وقت الحجز الفعلي).
 */
class PackageBusySlotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_teachers_busy_ranges_for_the_requested_local_day(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $packageA = $this->createActiveIndividualPackage($teacher);
        $packageB = $this->createActiveIndividualPackage($teacher);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $day = Carbon::now('UTC')->addWeek()->startOfDay();
        $busySession = ClassSession::create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $day->copy()->setTime(10, 0),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        $response = $this->as($studentToken)->getJson(
            "/api/packages/{$packageB->id}/busy-slots?date=".$day->toDateString()
        );

        $response->assertStatus(200);
        $slots = $response->json('data');
        $this->assertCount(1, $slots);
        $this->assertSame($busySession->scheduled_at->toIso8601String(), $slots[0]['start']);
    }

    /** جلسة معلم آخر لا علاقة لها إطلاقاً بهذه الباقة — لا يجوز ظهورها كوقت مشغول لهذا المعلم */
    public function test_it_does_not_include_another_teachers_sessions(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher);

        [$otherTeacher] = $this->createVerifiedTeacher();
        $day = Carbon::now('UTC')->addWeek()->startOfDay();
        ClassSession::create([
            'teacher_id' => $otherTeacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $day->copy()->setTime(10, 0),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson(
            "/api/packages/{$package->id}/busy-slots?date=".$day->toDateString()
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_cancelled_sessions_are_not_counted_as_busy(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher);
        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $day = Carbon::now('UTC')->addWeek()->startOfDay();
        ClassSession::create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $day->copy()->setTime(10, 0),
            'duration_min' => 60,
            'status' => 'cancelled',
        ]);

        $response = $this->as($studentToken)->getJson(
            "/api/packages/{$package->id}/busy-slots?date=".$day->toDateString()
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher, $user->createToken('t')->plainTextToken];
    }

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create();

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function createActiveIndividualPackage(Teacher $teacher): Package
    {
        $subject = Subject::create(['code' => 'bs-'.uniqid(), 'name_ar' => 'مادة']);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);
    }
}
