<?php

namespace Tests\Feature\Reschedule;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** GET /my-pending-actions — ما ينتظر تصرّف الطالب/المعلم في لوحته */
class PendingActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_a_proposal_appears_for_the_other_party_only(): void
    {
        [$teacherUser, $studentUser, $session] = $this->createSession(now()->addDays(3));

        $this->as($this->token($teacherUser))->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(4)->toDateTimeString(),
            'reason' => 'ظرف طارئ',
        ])->assertStatus(201);

        $this->as($this->token($studentUser))->getJson('/api/my-pending-actions')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.awaiting_response.0.type', 'reschedule')
            ->assertJsonPath('data.awaiting_response.0.session.id', $session->id)
            ->assertJsonPath('data.awaiting_response.0.session.other_party_name', $teacherUser->name);

        $this->as($this->token($teacherUser))->getJson('/api/my-pending-actions')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_a_missed_session_offers_a_makeup_until_one_party_proposes(): void
    {
        [$teacherUser, $studentUser, $session] = $this->createSession(now()->subDay(), ClassSession::OUTCOME_TEACHER_ABSENT);

        foreach ([$teacherUser, $studentUser] as $user) {
            $this->as($this->token($user))->getJson('/api/my-pending-actions')
                ->assertJsonPath('data.makeup_eligible.0.id', $session->id);
        }

        $this->as($this->token($studentUser))->postJson("/api/class-sessions/{$session->id}/makeup-requests", [
            'proposed_scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertStatus(201);

        $this->as($this->token($studentUser))->getJson('/api/my-pending-actions')
            ->assertJsonPath('data.count', 0);

        $this->as($this->token($teacherUser))->getJson('/api/my-pending-actions')
            ->assertJsonPath('data.count', 1)
            ->assertJsonCount(0, 'data.makeup_eligible')
            ->assertJsonPath('data.awaiting_response.0.type', 'makeup');
    }

    public function test_student_absence_and_other_peoples_sessions_are_not_listed(): void
    {
        $this->createSession(now()->subDay(), ClassSession::OUTCOME_STUDENT_NO_SHOW);
        [, , $otherSession] = $this->createSession(now()->subDay(), ClassSession::OUTCOME_TEACHER_ABSENT);
        [, $stranger] = $this->createSession(now()->addDays(5));

        $this->as($this->token($stranger))->getJson('/api/my-pending-actions')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $this->assertNotNull($otherSession);
    }

    public function test_an_unpaid_booking_is_listed_for_its_student_until_its_hold_lapses(): void
    {
        [$teacherUser, $studentUser, $session] = $this->createSession(now()->addDays(3));
        $booking = $session->booking;
        $booking->update(['status' => 'pending_payment', 'hold_expires_at' => now()->addHours(5)]);

        $this->as($this->token($studentUser))->getJson('/api/my-pending-actions')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.awaiting_payment.0.kind', 'booking')
            ->assertJsonPath('data.awaiting_payment.0.id', $booking->id)
            ->assertJsonPath('data.awaiting_payment.0.teacher_name', $teacherUser->name);

        // المعلم لا يدفع — لا شيء عنده
        $this->as($this->token($teacherUser))->getJson('/api/my-pending-actions')
            ->assertJsonPath('data.count', 0);

        // انقضت المهلة: يُحوَّل الحجز expired ويختفي
        $booking->update(['hold_expires_at' => now()->subMinute()]);
        $this->as($this->token($studentUser))->getJson('/api/my-pending-actions')
            ->assertJsonPath('data.count', 0)
            ->assertJsonCount(0, 'data.awaiting_payment');
        $this->assertSame('expired', $booking->fresh()->status);
    }

    private function token(User $user): string
    {
        return $user->createToken('t')->plainTextToken;
    }

    /**
     * @return array{0: User, 1: User, 2: ClassSession}
     */
    private function createSession(Carbon $scheduledAt, ?string $outcome = null): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $subject = Subject::create(['code' => 'pa-'.uniqid(), 'name_ar' => 'مادة']);
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
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => $outcome ? 'completed' : 'scheduled',
            'attendance_outcome' => $outcome,
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return [$teacherUser, $studentUser, $session];
    }
}
