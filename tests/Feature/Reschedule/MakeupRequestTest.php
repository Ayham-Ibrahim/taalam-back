<?php

namespace Tests\Feature\Reschedule;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\RescheduleRequest;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\MakeupSessionScheduled;
use App\Notifications\RescheduleRequestEscalated;
use App\Notifications\RescheduleResponseRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * طلب موعد الجلسة التعويضية بلا موظف: لجلسة فائتة غاب عنها المعلم (السياسة)، أي طرف يقترح
 * والآخر يوافق فتُنشأ التعويضية، أو يرفض فيُحال للأدمن. غياب الطالب لا يُعوَّض تلقائياً.
 */
class MakeupRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private User $studentUser;

    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        [$this->teacherUser, $this->studentUser, $this->session] = $this->createMissedSession(ClassSession::OUTCOME_TEACHER_ABSENT);
    }

    public function test_student_proposes_and_teacher_accepting_creates_the_makeup_session(): void
    {
        $proposedAt = now()->addDays(2)->startOfMinute();

        $this->propose($this->studentUser, $proposedAt)
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'makeup')
            ->assertJsonPath('data.counterparty_response', 'pending');

        Notification::assertSentTo($this->teacherUser, RescheduleResponseRequested::class, fn ($n) => $n->toArray($this->teacherUser)['kind'] === 'makeup');

        $request = RescheduleRequest::firstOrFail();
        $this->as($this->token($this->teacherUser))
            ->postJson("/api/reschedule-requests/{$request->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $makeup = ClassSession::where('makeup_for_session_id', $this->session->id)->firstOrFail();
        $this->assertTrue($makeup->is_makeup);
        $this->assertTrue($makeup->scheduled_at->eq($proposedAt));
        $this->assertSame(1, $makeup->attendees()->count());

        foreach ([$this->teacherUser, $this->studentUser] as $party) {
            Notification::assertSentTo($party, MakeupSessionScheduled::class, fn ($n) => $n->toArray($party)['byAgreement'] === true);
        }
    }

    public function test_teacher_can_propose_and_student_accepts(): void
    {
        $this->propose($this->teacherUser, now()->addDays(2))->assertStatus(201);
        $request = RescheduleRequest::firstOrFail();

        $this->as($this->token($this->studentUser))
            ->postJson("/api/reschedule-requests/{$request->id}/accept")
            ->assertOk();

        $this->assertTrue(ClassSession::where('makeup_for_session_id', $this->session->id)->exists());
    }

    public function test_student_absence_is_not_compensated_automatically(): void
    {
        [, $studentUser, $session] = $this->createMissedSession(ClassSession::OUTCOME_STUDENT_NO_SHOW);

        $this->propose($studentUser, now()->addDays(2), $session)->assertStatus(422);

        $this->as($this->token($studentUser))
            ->getJson("/api/class-sessions/{$session->id}")
            ->assertJsonPath('data.can_request_makeup', false);
    }

    public function test_a_session_that_already_has_a_makeup_cannot_get_another(): void
    {
        app(\App\Services\SessionService::class)->createMakeupSession($this->session, now()->addDays(3));

        $this->propose($this->studentUser, now()->addDays(2))->assertStatus(422);
    }

    public function test_decline_escalates_and_admin_approval_creates_the_makeup(): void
    {
        $admin = User::factory()->admin()->create();
        $this->propose($this->studentUser, now()->addDays(2))->assertStatus(201);
        $request = RescheduleRequest::firstOrFail();

        $this->as($this->token($this->teacherUser))
            ->postJson("/api/reschedule-requests/{$request->id}/decline")
            ->assertOk()
            ->assertJsonPath('data.escalated', true);

        $this->assertFalse(ClassSession::where('makeup_for_session_id', $this->session->id)->exists());
        Notification::assertSentTo($admin, RescheduleRequestEscalated::class, fn ($n) => $n->toArray($admin)['kind'] === 'makeup');

        $alternative = now()->addDays(4)->startOfMinute();
        $this->as($this->token($admin))
            ->postJson("/api/reschedule-requests/{$request->id}/approve", ['alternative_scheduled_at' => $alternative->toDateTimeString()])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved_with_alternative');

        $makeup = ClassSession::where('makeup_for_session_id', $this->session->id)->firstOrFail();
        $this->assertTrue($makeup->scheduled_at->eq($alternative));
    }

    public function test_admin_creating_the_makeup_directly_closes_the_pending_request(): void
    {
        $admin = User::factory()->admin()->create();
        $this->propose($this->studentUser, now()->addDays(2))->assertStatus(201);

        app(\App\Services\SessionService::class)->scheduleMakeupByAdmin($this->session->fresh(), $admin, now()->addDays(3));

        $this->assertSame('rejected', RescheduleRequest::firstOrFail()->status);
    }

    public function test_session_payload_offers_the_makeup_request_to_both_parties(): void
    {
        foreach ([$this->studentUser, $this->teacherUser] as $user) {
            $this->as($this->token($user))
                ->getJson("/api/class-sessions/{$this->session->id}")
                ->assertOk()
                ->assertJsonPath('data.can_request_makeup', true);
        }

        $this->propose($this->studentUser, now()->addDays(2))->assertStatus(201);

        $this->as($this->token($this->teacherUser))
            ->getJson("/api/class-sessions/{$this->session->id}")
            ->assertJsonPath('data.can_request_makeup', false)
            ->assertJsonPath('data.pending_reschedule_request.type', 'makeup')
            ->assertJsonPath('data.pending_reschedule_request.awaiting_my_response', true);
    }

    public function test_an_expired_booking_offers_no_makeup_to_either_party(): void
    {
        $this->session->booking->update(['status' => 'expired']);

        // الطالب لا يرى جلسة حجز منتهٍ أصلاً (ClassSessionPolicy) — المعلم يراها بلا زر التعويض
        $this->as($this->token($this->teacherUser))
            ->getJson("/api/class-sessions/{$this->session->id}")
            ->assertOk()
            ->assertJsonPath('data.can_request_makeup', false);

        foreach ([$this->studentUser, $this->teacherUser] as $user) {
            $this->as($this->token($user))
                ->getJson('/api/my-pending-actions')
                ->assertOk()
                ->assertJsonCount(0, 'data.makeup_eligible');
        }

        $this->propose($this->teacherUser, now()->addDays(2))->assertStatus(422);
        $this->assertFalse(RescheduleRequest::exists());
    }

    public function test_group_sessions_stay_with_the_admin(): void
    {
        [, $studentUser, $session] = $this->createMissedSession(ClassSession::OUTCOME_TEACHER_ABSENT, 'group');

        $this->propose($studentUser, now()->addDays(2), $session)->assertStatus(422);
    }

    private function propose(User $user, Carbon $proposedAt, ?ClassSession $session = null)
    {
        $session ??= $this->session;

        return $this->as($this->token($user))->postJson("/api/class-sessions/{$session->id}/makeup-requests", [
            'proposed_scheduled_at' => $proposedAt->toDateTimeString(),
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('t')->plainTextToken;
    }

    /**
     * @return array{0: User, 1: User, 2: ClassSession}
     */
    private function createMissedSession(string $outcome, string $format = 'individual'): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $subject = Subject::create(['code' => 'mk-'.uniqid(), 'name_ar' => 'مادة']);
        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => $format,
            'capacity' => $format === 'group' ? 5 : 1,
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
            'scheduled_at' => now()->subDay(),
            'duration_min' => 60,
            'status' => 'completed',
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
