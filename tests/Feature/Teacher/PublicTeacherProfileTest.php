<?php

namespace Tests\Feature\Teacher;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTeacherProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_public_resource_without_verification_documents(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified', 'bio' => 'معلم متمرس']);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.bio', 'معلم متمرس');
        $this->assertEquals(0.0, $response->json('data.stats.rating_avg'));
        $this->assertArrayNotHasKey('verification_documents', $response->json('data'));
        $this->assertArrayNotHasKey('rejection_reason', $response->json('data'));
    }

    public function test_student_cannot_view_unverified_teachers_profile(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'active_unverified']);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(404);
    }

    public function test_guest_with_no_token_can_view_a_verified_teachers_profile_and_packages(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified', 'bio' => 'معلم متمرس']);

        $profile = $this->getJson("/api/teachers/{$teacher->id}");
        $profile->assertStatus(200);
        $profile->assertJsonPath('data.bio', 'معلم متمرس');
        $this->assertArrayNotHasKey('verification_documents', $profile->json('data'));

        $packages = $this->getJson("/api/teachers/{$teacher->id}/packages");
        $packages->assertStatus(200);
    }

    public function test_guest_cannot_view_unverified_teachers_profile(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'active_unverified']);

        $response = $this->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(404);
    }

    public function test_teacher_still_sees_full_management_view_of_own_profile(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'active_unverified']);
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('verification_documents', $response->json('data'));
    }

    public function test_search_filters_by_teacher_name(): void
    {
        $matchUser = User::factory()->teacher()->create(['name' => 'أحمد الشمري']);
        Teacher::create(['user_id' => $matchUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $otherUser = User::factory()->teacher()->create(['name' => 'سالم العتيبي']);
        Teacher::create(['user_id' => $otherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $response = $this->getJson('/api/teachers/search?q='.urlencode('الشمري'));

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('user.name');
        $this->assertTrue($names->contains('أحمد الشمري'));
        $this->assertFalse($names->contains('سالم العتيبي'));
    }

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create();

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }
}
