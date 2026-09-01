<?php

namespace Tests\Feature\Package;

use App\Models\AvailabilitySlot;
use App\Models\Package;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPackageDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_list_a_verified_teachers_active_packages_only(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $active = $this->createIndividualPackage($teacher, status: 'active');
        $this->createIndividualPackage($teacher, status: 'draft');
        $this->createIndividualPackage($teacher, status: 'pending_approval');

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/teachers/{$teacher->id}/packages");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$active->id], $ids->all());
        $this->assertArrayNotHasKey('teacher_price', $response->json('data.0'));
    }

    /**
     * عمداً لا تُخفى — الطالب يجب أن يرى الباقة الجماعية المنتهية موسومة
     * "انتهت" (PackagesSection.jsx) بدل اختفائها بصمت، فيعرف أنها انتهت
     * تحديداً بدل ألا يجد لها أثراً إطلاقاً. المنع الفعلي من الحجز يبقى قائماً
     * عبر Package::isBookable() في مسار الحجز نفسه، لا في هذه القائمة.
     */
    public function test_a_group_package_whose_schedule_dates_all_passed_still_appears_in_the_listing(): void
    {
        [$teacher] = $this->createVerifiedTeacher();

        $activeIndividual = $this->createIndividualPackage($teacher, status: 'active');

        $expiredGroup = $this->createGroupPackage($teacher);
        $expiredGroup->schedules()->create(['date' => now()->subDays(5)->toDateString(), 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $futureGroup = $this->createGroupPackage($teacher);
        $futureGroup->schedules()->create(['date' => now()->addDays(5)->toDateString(), 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $draftPackage = $this->createIndividualPackage($teacher, status: 'draft');

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/teachers/{$teacher->id}/packages");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($activeIndividual->id));
        $this->assertTrue($ids->contains($futureGroup->id));
        $this->assertTrue($ids->contains($expiredGroup->id));
        $this->assertFalse($ids->contains($draftPackage->id));
    }

    public function test_packages_of_unverified_teacher_are_not_discoverable(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'pending_verification']);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/teachers/{$teacher->id}/packages");

        $response->assertStatus(404);
    }

    public function test_student_can_view_a_verified_teachers_availability_slots(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        AvailabilitySlot::create(['teacher_id' => $teacher->id, 'day_of_week' => 3]);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/teachers/{$teacher->id}/availability-slots");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * indexForTeacher كان لا يحمّل علاقة stages إطلاقاً (فتظهر فارغة دوماً في
     * الاستجابة رغم whenLoaded('stages') في StudentPackageResource) — الطالب
     * المتصفح لباقات معلم يحتاج رؤية المرحلة/الصفوف التي تستهدفها كل باقة.
     */
    public function test_package_listing_exposes_its_stages_and_grades(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $stage = Stage::create(['code' => 'disc-'.uniqid(), 'name_ar' => 'مرحلة الاكتشاف']);

        $package = $this->createIndividualPackage($teacher, status: 'active');
        $package->stages()->attach($stage->id);
        $package->update(['grades' => [4, 5]]);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/teachers/{$teacher->id}/packages");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.stages.0.id', $stage->id)
            ->assertJsonPath('data.0.grades', [4, 5]);
    }

    public function test_student_cannot_view_unverified_teachers_availability_slots(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'active_unverified']);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/teachers/{$teacher->id}/availability-slots");

        $response->assertStatus(403);
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

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create();

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function createIndividualPackage(Teacher $teacher, string $status): Package
    {
        $subject = Subject::create(['code' => 'subj-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);
        $computed = app(PricingService::class)->calculateStudentPrice(100, 60, 4);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة فردية',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => $status,
            'approved_at' => $status === 'active' ? now() : null,
        ]);
    }

    private function createGroupPackage(Teacher $teacher): Package
    {
        $subject = Subject::create(['code' => 'subj-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);
        $computed = app(PricingService::class)->calculateStudentPrice(100, 60, 2);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة جماعية',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 5,
            'sessions_count' => 2,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
        ]);
    }
}
