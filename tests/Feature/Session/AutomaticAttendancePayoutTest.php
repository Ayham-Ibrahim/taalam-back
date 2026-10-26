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
use App\Services\PayoutService;
use App\Services\SessionService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الحضور التلقائي (session_join_logs) يحسم عند انتهاء الجلسة نسبة مستحق المعلم منها:
 * دخل الطرفان → كاملة، دخل المعلم وحده دون إبلاغ مسبق من الطالب → نسبة الإعداد،
 * لم يدخل المعلم → صفر. الحالة تبقى 'completed' كما كانت (لا أثر ظاهر للطالب/المعلم).
 */
class AutomaticAttendancePayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // الجلسات يجب أن تبدأ بعد إنشاء جدول session_join_logs (trackingStartedAt) وإلا
        // تُعدّ untracked — الجدول أُنشئ لحظة تشغيل الاختبارات، فنتقدم بالزمن.
        $this->travel(2)->days();
    }

    public function test_teacher_and_student_joined_gives_the_full_amount(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin($session, $studentUser, 'student');

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(ClassSession::OUTCOME_ATTENDED, $session->attendance_outcome);
        $this->assertEquals(100, (float) $session->teacher_payout_percent);
        $this->assertEquals(100.0, (float) $this->generatePayout($teacher)->gross_amount);
    }

    public function test_teacher_joined_and_student_did_not_gives_the_configured_percent(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(ClassSession::OUTCOME_STUDENT_NO_SHOW, $session->attendance_outcome);
        $this->assertEquals(50, (float) $session->teacher_payout_percent);
        $this->assertEquals(50.0, (float) $this->generatePayout($teacher)->gross_amount);
    }

    public function test_changing_the_percent_affects_only_sessions_completed_afterwards(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$first] = $this->createEndedSession($teacher);
        $this->logJoin($first, $teacherUser, 'teacher');
        app(SessionService::class)->completeEndedSessions();

        app(SettingsService::class)->set('student_no_show_teacher_payout_percent', 30);

        [$second] = $this->createEndedSession($teacher);
        $this->logJoin($second, $teacherUser, 'teacher');
        app(SessionService::class)->completeEndedSessions();

        $this->assertEquals(50, (float) $first->fresh()->teacher_payout_percent);
        $this->assertEquals(30, (float) $second->fresh()->teacher_payout_percent);
        $this->assertEquals(80.0, (float) $this->generatePayout($teacher)->gross_amount);
    }

    public function test_teacher_who_did_not_join_gets_nothing(): void
    {
        [$teacher] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $studentUser, 'student');

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(ClassSession::OUTCOME_TEACHER_ABSENT, $session->attendance_outcome);

        $payout = $this->generatePayout($teacher);
        $this->assertEquals(0.0, (float) $payout->gross_amount);
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $session->id]);
    }

    /** الحضور اليدوي (ومنه excused) لا يدخل في التصنيف — الحكم لسجل الدخول وحده */
    public function test_manual_attendance_is_ignored_and_only_the_join_log_counts(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, , $attendee] = $this->createEndedSession($teacher);
        $attendee->update(['attendance' => 'excused']);
        $this->logJoin($session, $teacherUser, 'teacher');

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_STUDENT_NO_SHOW, $session->fresh()->attendance_outcome);
        $this->assertEquals(50.0, (float) $this->generatePayout($teacher)->gross_amount);
    }

    /** سجل انضمام من موعد سابق للجلسة (قبل تغيير موعدها) لا يُعدّ حضوراً */
    public function test_a_join_outside_the_session_window_is_not_counted(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin($session, $studentUser, 'student', $session->scheduled_at->clone()->subDay());

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_STUDENT_NO_SHOW, $session->fresh()->attendance_outcome);
    }

    /** جلسة بدأت قبل بدء تسجيل الانضمام لا دليل حضور لها — تبقى كاملة كما كانت */
    public function test_a_session_before_join_tracking_started_keeps_the_full_amount(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher, SessionJoinLog::trackingStartedAt()->clone()->subDays(3));

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(ClassSession::OUTCOME_UNTRACKED, $session->attendance_outcome);
        $this->assertNull($session->teacher_payout_percent);
        $this->assertEquals(100.0, (float) $this->generatePayout($teacher, $session->scheduled_at)->gross_amount);
    }

    public function test_the_outcome_is_hidden_from_the_student_and_the_teacher_but_shown_to_the_admin(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        app(SessionService::class)->completeEndedSessions();

        foreach ([$studentUser, $teacherUser] as $user) {
            $response = $this->as($user->createToken('t')->plainTextToken)
                ->getJson("/api/class-sessions/{$session->id}")
                ->assertOk();

            $this->assertSame('completed', $response->json('data.status'));
            $this->assertArrayNotHasKey('attendance_outcome', $response->json('data'));
            $this->assertArrayNotHasKey('teacher_payout_percent', $response->json('data'));
        }

        $this->assertArrayNotHasKey('attendance_outcome', $session->fresh()->toArray());

        $admin = User::factory()->admin()->create();
        $this->as($admin->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.attendance_outcome', ClassSession::OUTCOME_STUDENT_NO_SHOW);
    }

    private function generatePayout(Teacher $teacher, ?Carbon $around = null)
    {
        $around ??= now();

        return app(PayoutService::class)->generateForPeriod($teacher, $around->clone()->subDay(), $around->clone()->addDay());
    }

    private function logJoin(ClassSession $session, User $user, string $role, ?Carbon $at = null): void
    {
        $at ??= $session->scheduled_at->clone()->addMinutes(2);

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
     * باقة فردية بجلسة واحدة — مستحق المعلم الكامل لها 100.
     *
     * @return array{0: ClassSession, 1: User, 2: SessionAttendee}
     */
    private function createEndedSession(Teacher $teacher, ?Carbon $scheduledAt = null): array
    {
        $subject = Subject::create(['code' => 'aa-'.uniqid(), 'name_ar' => 'مادة']);

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

        $attendee = SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return [$session, $studentUser, $attendee];
    }
}
