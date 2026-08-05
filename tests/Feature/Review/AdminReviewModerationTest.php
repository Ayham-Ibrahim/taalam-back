<?php

namespace Tests\Feature\Review;

use App\Models\Review;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_all_reviews_with_names_and_unhide_one(): void
    {
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $teacherUser = User::factory()->teacher()->create(['name' => 'معلم الرياضيات']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $studentUser = User::factory()->student()->create(['name' => 'طالبة مجتهدة']);
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        $review = Review::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'rating' => 1,
            'comment' => 'محتوى غير لائق',
            'is_hidden' => true,
            'hidden_by' => $admin->id,
            'hidden_reason' => 'مخالف للسياسة',
        ]);

        $list = $this->as($adminToken)->getJson('/api/reviews?is_hidden=1');
        $list->assertStatus(200);
        $list->assertJsonPath('data.0.student_name', 'طالبة مجتهدة');
        $list->assertJsonPath('data.0.teacher_name', 'معلم الرياضيات');

        $unhide = $this->as($adminToken)->postJson("/api/reviews/{$review->id}/unhide");
        $unhide->assertStatus(200);
        $unhide->assertJsonPath('data.is_hidden', false);
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'is_hidden' => false, 'hidden_reason' => null]);
    }

    public function test_non_admin_cannot_list_all_reviews(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson('/api/reviews');

        $response->assertStatus(403);
    }
}
