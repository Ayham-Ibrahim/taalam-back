<?php

namespace Tests\Feature\Reschedule;

use App\Jobs\EscalateRescheduleRequestsJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\RescheduleRequest;
use App\Models\SessionAttendee;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\RescheduleRequestEscalated;
use App\Notifications\RescheduleRequestReviewed;
use App\Notifications\RescheduleRequestSubmitted;
use App\Notifications\RescheduleResponseRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * المسار التلقائي لتغيير الموعد: الطرف الآخر يوافق فيُطبَّق الموعد بلا أدمن، أو يرفض/لا يرد
 * فيُصعَّد الطلب للأدمن. تبليغ الغياب والطلب المتأخر جداً يبقيان للأدمن مباشرة.
 */
class CounterpartyRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private User $studentUser;

    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        [$this->teacherUser, $this->studentUser, $this->session] = $this->createIndividualSession(now()->addDays(3));
    }

    public function test_a_proposal_goes_to_the_other_party_not_the_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->propose($this->teacherUser, now()->addDays(4))
            ->assertStatus(201)
            ->assertJsonPath('data.counterparty_response', 'pending');

        $request = RescheduleRequest::firstOrFail();
        // 12 ساعة من الآن (الجلسة بعد 3 أيام، فالحد قبلها بساعتين أبعد)
        $this->assertTrue($request->counterparty_deadline->between(now()->addHours(12)->subMinute(), now()->addHours(12)->addMinute()));
        $this->assertNull($request->sla_due_at);

        Notification::assertSentTo($this->studentUser, RescheduleResponseRequested::class);
        Notification::assertSentTo($this->teacherUser, RescheduleRequestSubmitted::class, fn ($n) => $n->toArray($this->teacherUser)['awaitingCounterparty'] === true);
        Notification::assertNothingSentTo($admin);
    }

    public function test_the_deadline_ends_two_hours_before_a_close_session(): void
    {
        [$teacherUser, , $session] = $this->createIndividualSession(now()->addHours(6));

        $this->propose($teacherUser, now()->addDays(2), $session)->assertStatus(201);

        $deadline = RescheduleRequest::firstOrFail()->counterparty_deadline;
        $this->assertTrue($deadline->between(now()->addHours(4)->subMinute(), now()->addHours(4)->addMinute()));
    }

    public function test_counterparty_acceptance_applies_the_new_time_immediately(): void
    {
        $proposedAt = now()->addDays(4)->startOfMinute();
        $this->propose($this->teacherUser, $proposedAt)->assertStatus(201);
        $request = RescheduleRequest::firstOrFail();

        $this->as($this->token($this->studentUser))
            ->postJson("/api/reschedule-requests/{$request->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.counterparty_response', 'accepted');

        $session = $this->session->fresh();
        $this->assertTrue($session->scheduled_at->eq($proposedAt));
        $this->assertNotNull($session->original_scheduled_at);

        foreach ([$this->teacherUser, $this->studentUser] as $party) {
            Notification::assertSentTo($party, RescheduleRequestReviewed::class, fn ($n) => $n->toArray($party)['byCounterparty'] === true);
        }
    }

    public function test_a_student_proposal_is_answered_by_the_teacher(): void
    {
        $this->propose($this->studentUser, now()->addDays(4))->assertStatus(201);
        $request = RescheduleRequest::firstOrFail();

        Notification::assertSentTo($this->teacherUser, RescheduleResponseRequested::class);

        $this->as($this->token($this->studentUser))
            ->postJson("/api/reschedule-requests/{$request->id}/accept")
            ->assertForbidden();

        $this->as($this->token($this->teacherUser))
            ->postJson("/api/reschedule-requests/{$request->id}/accept")
            ->assertOk();
    }

    public function test_decline_escalates_to_the_admin_who_can_still_decide(): void
    {
        $admin = User::factory()->admin()->create();
        $this->propose($this->teacherUser, now()->addDays(4))->assertStatus(201);
        $request = RescheduleRequest::firstOrFail();
        $originalTime = $this->session->scheduled_at->copy();

        $this->as($this->token($this->studentUser))
            ->postJson("/api/reschedule-requests/{$request->id}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.counterparty_response', 'declined')
            ->assertJsonPath('data.escalated', true);

        $this->assertTrue($this->session->fresh()->scheduled_at->eq($originalTime));
        $this->assertNotNull($request->fresh()->sla_due_at);
        Notification::assertSentTo($admin, RescheduleRequestEscalated::class, fn ($n) => $n->toArray($admin)['forReviewer'] === true);
        Notification::assertSentTo($this->teacherUser, RescheduleRequestEscalated::class);

        $this->as($this->token($admin))
            ->postJson("/api/reschedule-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_no_response_before_the_deadline_escalates_to_the_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $this->propose($this->teacherUser, now()->addDays(4))->assertStatus(201);
        $request = RescheduleRequest::firstOrFail();

        $this->travel(13)->hours();
        (new EscalateRescheduleRequestsJob)->handle(app(\App\Services\RescheduleService::class));

        $request->refresh();
        $this->assertSame('timeout', $request->counterparty_response);
        $this->assertTrue($request->escalated);
        $this->assertSame('pending', $request->status);
        Notification::assertSentTo($admin, RescheduleRequestEscalated::class);
        // الطرف الذي فاته الرد يُبلَّغ أيضاً
        Notification::assertSentTo($this->studentUser, RescheduleRequestEscalated::class);

        $this->as($this->token($this->studentUser))
            ->postJson("/api/reschedule-requests/{$request->id}/accept")
            ->assertStatus(422);
    }

    public function test_acceptance_is_refused_if_the_proposed_time_became_taken(): void
    {
        $proposedAt = now()->addDays(4)->startOfMinute();
        $this->propose($this->teacherUser, $proposedAt)->assertStatus(201);
        $request = RescheduleRequest::firstOrFail();
        $originalTime = $this->session->scheduled_at->copy();

        // جلسة أخرى للمعلم نفسه حُجزت في الموعد المقترح بعد إرسال الطلب
        $this->createIndividualSession($proposedAt, Teacher::where('user_id', $this->teacherUser->id)->first());

        $this->as($this->token($this->studentUser))
            ->postJson("/api/reschedule-requests/{$request->id}/accept")
            ->assertStatus(422);

        $this->assertTrue($this->session->fresh()->scheduled_at->eq($originalTime));
        $this->assertSame('pending', $request->fresh()->counterparty_response);
    }

    public function test_admin_and_strangers_cannot_answer_as_the_counterparty(): void
    {
        $this->propose($this->teacherUser, now()->addDays(4))->assertStatus(201);
        $request = RescheduleRequest::firstOrFail();
        $admin = User::factory()->admin()->create();
        [, $stranger] = $this->createIndividualSession(now()->addDays(5));

        foreach ([$admin, $stranger, $this->teacherUser] as $user) {
            $this->as($this->token($user))
                ->postJson("/api/reschedule-requests/{$request->id}/accept")
                ->assertForbidden();
        }
    }

    public function test_absence_report_without_a_proposed_time_goes_straight_to_the_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->as($this->token($this->teacherUser))
            ->postJson("/api/class-sessions/{$this->session->id}/reschedule-requests", ['reason' => 'مرض مفاجئ'])
            ->assertStatus(201);

        $request = RescheduleRequest::firstOrFail();
        $this->assertNull($request->counterparty_response);
        $this->assertNotNull($request->sla_due_at);
        Notification::assertSentTo($admin, RescheduleRequestSubmitted::class, fn ($n) => $n->toArray($admin)['forReviewer'] === true);
        Notification::assertNotSentTo($this->studentUser, RescheduleResponseRequested::class);
    }

    public function test_a_request_too_close_to_the_session_goes_straight_to_the_admin(): void
    {
        [$teacherUser, $studentUser, $session] = $this->createIndividualSession(now()->addHours(2)->addMinutes(30));

        $this->propose($teacherUser, now()->addDays(2), $session)->assertStatus(201);

        $this->assertNull(RescheduleRequest::firstOrFail()->counterparty_response);
        Notification::assertNotSentTo($studentUser, RescheduleResponseRequested::class);
    }

    public function test_requires_admin_setting_disables_the_automatic_flow(): void
    {
        Setting::create([
            'key' => 'reschedule_requires_admin',
            'value' => '1',
            'type' => 'boolean',
            'group' => 'booking',
            'label_ar' => 'كل الطلبات للأدمن',
        ]);

        $this->propose($this->teacherUser, now()->addDays(4))->assertStatus(201);

        $this->assertNull(RescheduleRequest::firstOrFail()->counterparty_response);
        Notification::assertNotSentTo($this->studentUser, RescheduleResponseRequested::class);
    }

    public function test_session_payload_tells_only_the_counterparty_to_respond(): void
    {
        $this->propose($this->teacherUser, now()->addDays(4))->assertStatus(201);

        $this->as($this->token($this->studentUser))
            ->getJson("/api/class-sessions/{$this->session->id}")
            ->assertOk()
            ->assertJsonPath('data.pending_reschedule_request.awaiting_my_response', true)
            ->assertJsonPath('data.pending_reschedule_request.requester_role', 'teacher');

        $this->as($this->token($this->teacherUser))
            ->getJson("/api/class-sessions/{$this->session->id}")
            ->assertOk()
            ->assertJsonPath('data.pending_reschedule_request.awaiting_my_response', false)
            ->assertJsonPath('data.pending_reschedule_request.requested_by_me', true);
    }

    public function test_admin_list_can_separate_requests_needing_a_decision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->propose($this->teacherUser, now()->addDays(4))->assertStatus(201);

        [$otherTeacher, , $otherSession] = $this->createIndividualSession(now()->addDays(5));
        $this->as($this->token($otherTeacher))
            ->postJson("/api/class-sessions/{$otherSession->id}/reschedule-requests", ['reason' => 'ظرف طارئ'])
            ->assertStatus(201);

        $this->as($this->token($admin))->getJson('/api/reschedule-requests?stage=needs_admin')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterpartyResponse', null);

        $this->as($this->token($admin))->getJson('/api/reschedule-requests?stage=awaiting_counterparty')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterpartyResponse', 'pending');
    }

    private function propose(User $user, Carbon $proposedAt, ?ClassSession $session = null)
    {
        $session ??= $this->session;

        return $this->as($this->token($user))->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => $proposedAt->toDateTimeString(),
            'reason' => 'ظرف طارئ',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('t')->plainTextToken;
    }

    /**
     * @return array{0: User, 1: User, 2: ClassSession}
     */
    private function createIndividualSession(Carbon $scheduledAt, ?Teacher $teacher = null): array
    {
        if (! $teacher) {
            $teacherUser = User::factory()->teacher()->create();
            $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        }

        $subject = Subject::create(['code' => 'cr-'.uniqid(), 'name_ar' => 'مادة']);
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
            'status' => 'scheduled',
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return [$teacher->user, $studentUser, $session];
    }
}
