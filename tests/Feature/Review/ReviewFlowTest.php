<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\Review;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_review_a_completed_session_they_attended(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$student, $studentToken, $session] = $this->createAttendedCompletedSession($teacher);

        $response = $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reviews", [
            'rating' => 5,
            'comment' => 'ممتاز',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.rating', 5);
        $this->assertDatabaseHas('reviews', ['student_id' => $student->id, 'teacher_id' => $teacher->id, 'rating' => 5]);
    }

    public function test_cannot_review_a_session_that_is_not_completed(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $studentToken, $session] = $this->createAttendedCompletedSession($teacher, status: 'scheduled');

        $response = $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reviews", [
            'rating' => 4,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_review_the_same_session_twice(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $studentToken, $session] = $this->createAttendedCompletedSession($teacher);

        $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reviews", ['rating' => 5])->assertStatus(201);

        $second = $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reviews", ['rating' => 3]);
        $second->assertStatus(422);
    }

    public function test_teacher_rating_avg_and_reviews_count_update_automatically(): void
    {
        [$teacher] = $this->createVerifiedTeacher();

        [, $token1, $session1] = $this->createAttendedCompletedSession($teacher);
        $this->as($token1)->postJson("/api/class-sessions/{$session1->id}/reviews", ['rating' => 5])->assertStatus(201);

        $this->assertEquals(1, $teacher->fresh()->reviews_count);
        $this->assertEquals(5.0, (float) $teacher->fresh()->rating_avg);

        [, $token2, $session2] = $this->createAttendedCompletedSession($teacher);
        $this->as($token2)->postJson("/api/class-sessions/{$session2->id}/reviews", ['rating' => 3])->assertStatus(201);

        $this->assertEquals(2, $teacher->fresh()->reviews_count);
        $this->assertEquals(4.0, (float) $teacher->fresh()->rating_avg);
    }

    public function test_hidden_review_excluded_from_rating_average(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $adminToken] = $this->createAdmin();

        [, $token1, $session1] = $this->createAttendedCompletedSession($teacher);
        $create = $this->as($token1)->postJson("/api/class-sessions/{$session1->id}/reviews", ['rating' => 1]);
        $reviewId = $create->json('data.id');

        [, $token2, $session2] = $this->createAttendedCompletedSession($teacher);
        $this->as($token2)->postJson("/api/class-sessions/{$session2->id}/reviews", ['rating' => 5])->assertStatus(201);

        $this->assertEquals(3.0, (float) $teacher->fresh()->rating_avg);

        $this->as($adminToken)->postJson("/api/reviews/{$reviewId}/hide", ['reason' => 'محتوى غير لائق'])
            ->assertStatus(200);

        $this->assertEquals(1, $teacher->fresh()->reviews_count);
        $this->assertEquals(5.0, (float) $teacher->fresh()->rating_avg);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.hidden']);
    }

    public function test_teacher_can_respond_to_review_only_once(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        [, $studentToken, $session] = $this->createAttendedCompletedSession($teacher);

        $create = $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reviews", ['rating' => 4]);
        $reviewId = $create->json('data.id');

        $first = $this->as($teacherToken)->postJson("/api/reviews/{$reviewId}/respond", ['response' => 'شكراً لك']);
        $first->assertStatus(200);

        $second = $this->as($teacherToken)->postJson("/api/reviews/{$reviewId}/respond", ['response' => 'رد ثانٍ']);
        $second->assertStatus(422);
    }

    public function test_student_can_list_their_own_reviews(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $studentToken, $session] = $this->createAttendedCompletedSession($teacher);
        $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reviews", ['rating' => 4, 'comment' => 'جيد'])
            ->assertStatus(201);

        // مراجعة طالب آخر لنفس المعلم — يجب ألا تظهر ضمن "تقييماتي" لهذا الطالب
        [, $otherToken, $otherSession] = $this->createAttendedCompletedSession($teacher);
        $this->as($otherToken)->postJson("/api/class-sessions/{$otherSession->id}/reviews", ['rating' => 2])
            ->assertStatus(201);

        $response = $this->as($studentToken)->getJson('/api/me/reviews');

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame(4, $items->first()['rating']);
        $this->assertSame('جيد', $items->first()['comment']);
        $this->assertTrue($items->first()['can_edit']);
        $this->assertSame($session->id, $items->first()['class_session_id']);
    }

    public function test_guest_cannot_list_reviews(): void
    {
        $this->getJson('/api/me/reviews')->assertStatus(401);
    }

    public function test_review_edit_window_is_enforced(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$student, $studentToken, $session] = $this->createAttendedCompletedSession($teacher);

        $create = $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reviews", ['rating' => 3]);
        $reviewId = $create->json('data.id');

        Review::whereKey($reviewId)->update(['edit_deadline' => now()->subHour()]);

        $update = $this->as($studentToken)->putJson("/api/reviews/{$reviewId}", ['rating' => 5]);
        $update->assertStatus(422);
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

    /**
     * @return array{0: User, 1: string}
     */
    private function createAdmin(): array
    {
        $admin = User::factory()->admin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }

    /**
     * @return array{0: Student, 1: string, 2: ClassSession}
     */
    private function createAttendedCompletedSession(Teacher $teacher, string $status = 'completed'): array
    {
        $subject = Subject::create(['code' => 'rv-'.uniqid(), 'name_ar' => 'مادة']);

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
            'scheduled_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'duration_min' => 60,
            'status' => $status,
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'present',
        ]);

        return [$student, $studentUser->createToken('t')->plainTextToken, $session];
    }
}
