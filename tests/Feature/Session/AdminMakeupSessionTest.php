<?php

namespace Tests\Feature\Session;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\SessionJoinLog;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\MakeupSessionScheduled;
use App\Services\PayoutService;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * الأدمن ينشئ جلسة تعويضية يدوياً — فقط لجلسة حسم الحضور التلقائي أن المعلم غاب عنها
 * أو لم يحضرها أي طالب. جلسة عُقدت فعلاً لا تُعوَّض.
 */
class AdminMakeupSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // الجلسات يجب أن تبدأ بعد إنشاء جدول session_join_logs وإلا تُعدّ untracked
        $this->travel(2)->days();
    }

    public function test_admin_creates_a_makeup_for_a_session_the_teacher_missed(): void
    {
        Notification::fake();
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $studentUser, 'student');
        app(SessionService::class)->completeEndedSessions();

        $at = now()->addDays(3)->setTime(15, 0);

        $response = $this->asAdmin()
            ->postJson("/api/class-sessions/{$session->id}/makeup", ['scheduled_at' => $at->toIso8601String(), 'notes' => 'تعويض غياب'])
            ->assertCreated();

        $makeup = ClassSession::findOrFail($response->json('data.id'));
        $this->assertTrue($makeup->is_makeup);
        $this->assertSame($session->id, $makeup->makeup_for_session_id);
        $this->assertSame($session->sequence_no, $makeup->sequence_no);
        $this->assertSame('scheduled', $makeup->status);
        $this->assertTrue($makeup->scheduled_at->eq($at));
        $this->assertDatabaseHas('session_attendees', ['class_session_id' => $makeup->id, 'student_id' => $studentUser->student->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.makeup_created']);

        Notification::assertSentTo($teacherUser, MakeupSessionScheduled::class);
        Notification::assertSentTo($studentUser, MakeupSessionScheduled::class);

        // قائمة الأدمن تُعلّم الأصلية بأن لها تعويضية — فيختفي زر الإنشاء
        $rows = collect($this->asAdmin()->getJson("/api/class-sessions?teacher_id={$teacher->id}")->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$session->id]['has_makeup']);
        $this->assertFalse($rows[$makeup->id]['has_makeup']);
    }

    public function test_admin_can_create_a_makeup_for_a_session_no_student_attended(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_STUDENT_NO_SHOW, $session->fresh()->attendance_outcome);

        $this->asAdmin()
            ->postJson("/api/class-sessions/{$session->id}/makeup", ['scheduled_at' => now()->addDays(3)->toIso8601String()])
            ->assertCreated();
    }

    public function test_a_session_that_was_actually_held_cannot_be_compensated(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin($session, $studentUser, 'student');
        app(SessionService::class)->completeEndedSessions();

        $this->asAdmin()
            ->postJson("/api/class-sessions/{$session->id}/makeup", ['scheduled_at' => now()->addDays(3)->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('session');

        $this->assertDatabaseMissing('class_sessions', ['makeup_for_session_id' => $session->id]);
    }

    public function test_a_session_that_has_not_ended_cannot_be_compensated(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher, now()->addDay());

        $this->asAdmin()
            ->postJson("/api/class-sessions/{$session->id}/makeup", ['scheduled_at' => now()->addDays(3)->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('session');
    }

    public function test_a_session_gets_only_one_makeup(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);
        app(SessionService::class)->completeEndedSessions();

        $this->asAdmin()
            ->postJson("/api/class-sessions/{$session->id}/makeup", ['scheduled_at' => now()->addDays(3)->toIso8601String()])
            ->assertCreated();

        $this->asAdmin()
            ->postJson("/api/class-sessions/{$session->id}/makeup", ['scheduled_at' => now()->addDays(4)->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('session');

        $this->assertSame(1, ClassSession::where('makeup_for_session_id', $session->id)->count());
    }

    public function test_the_makeup_time_must_be_in_the_future(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);
        app(SessionService::class)->completeEndedSessions();

        $this->asAdmin()
            ->postJson("/api/class-sessions/{$session->id}/makeup", ['scheduled_at' => now()->subHour()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_only_the_admin_can_create_a_makeup(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);
        app(SessionService::class)->completeEndedSessions();

        $this->as($teacherUser->createToken('t')->plainTextToken)
            ->postJson("/api/class-sessions/{$session->id}/makeup", ['scheduled_at' => now()->addDays(3)->toIso8601String()])
            ->assertForbidden();
    }

    /** الأصلية التي غاب عنها المعلم 0%، والتعويضية التي عقدها كاملة — يُصرف للجلسة مرة واحدة */
    public function test_the_teacher_is_paid_once_for_a_missed_session_and_its_makeup(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        app(SessionService::class)->completeEndedSessions();

        $makeup = app(SessionService::class)->scheduleMakeupByAdmin($session->fresh(), User::factory()->admin()->create(), now()->addHour());

        $this->travel(3)->hours();
        $this->logJoin($makeup, $teacherUser, 'teacher');
        $this->logJoin($makeup, $studentUser, 'student');
        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_ATTENDED, $makeup->fresh()->attendance_outcome);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        $this->assertEquals(100.0, (float) $payout->gross_amount);
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $session->id]);
    }

    private function asAdmin()
    {
        return $this->as(User::factory()->admin()->create()->createToken('t')->plainTextToken);
    }

    private function logJoin(ClassSession $session, User $user, string $role): void
    {
        $at = $session->scheduled_at->clone()->addMinutes(2);

        SessionJoinLog::create([
            'class_session_id' => $session->id,
            'user_id' => $user->id,
            'role' => $role,
            'student_id' => $role === 'student' ? $user->student->id : null,
            'first_joined_at' => $at,
            'last_joined_at' => $at,
            'join_count' => 1,
        ]);
    }

    private function createTeacher(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher, $teacherUser];
    }

    /**
     * باقة فردية مدفوعة بجلسة واحدة — مستحق المعلم الكامل لها 100.
     *
     * @return array{0: ClassSession, 1: User}
     */
    private function createEndedSession(Teacher $teacher, ?Carbon $scheduledAt = null): array
    {
        $subject = Subject::create(['code' => 'mk-'.uniqid(), 'name_ar' => 'مادة']);

        $package = Package::create([
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
            'sessions_total' => 1,
            'sessions_remaining' => 1,
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt ?? now()->subHours(2),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return [$session, $studentUser->fresh('student')];
    }
}
