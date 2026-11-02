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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الحضور التلقائي (session_join_logs) يحسم عند انتهاء الجلسة نسبة مستحق المعلم منها:
 * دخل الطرفان → كاملة، دخل المعلم وحده → تعويض ثابت من الإعداد بدل مستحقها،
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

        app(SettingsService::class)->set('student_no_show_teacher_compensation_amount', 30);
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

    /** دخل المعلم وحده → التعويض الثابت من الإعداد بدل مستحق الجلسة (100) */
    public function test_teacher_joined_and_student_did_not_gives_the_configured_fixed_compensation(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(ClassSession::OUTCOME_STUDENT_NO_SHOW, $session->attendance_outcome);
        $this->assertEquals(0, (float) $session->teacher_payout_percent);
        $this->assertEquals(30, (float) $session->teacher_compensation_amount);
        $this->assertEquals(30.0, (float) $this->generatePayout($teacher)->gross_amount);
    }

    public function test_only_a_student_no_show_gets_the_compensation(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin($session, $studentUser, 'student');

        app(SessionService::class)->completeEndedSessions();

        $this->assertNull($session->fresh()->teacher_compensation_amount);
    }

    /** التعويض الثابت يُصرف مرة واحدة على الأصلية — لا تعويض عن جلسة تعويضية لم يحضرها الطلاب */
    public function test_a_makeup_session_student_no_show_gets_no_compensation(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);
        $session->update(['is_makeup' => true]);
        $this->logJoin($session, $teacherUser, 'teacher');

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame(ClassSession::OUTCOME_STUDENT_NO_SHOW, $session->attendance_outcome);
        $this->assertNull($session->teacher_compensation_amount);
        $this->assertNoPayoutGenerated($teacher);
    }

    /** باقة مجانية لا مستحق للمعلم عنها — فغياب الطالب لا يولّد تعويضاً ثابتاً تتحمّله المنصة */
    public function test_a_free_package_session_student_no_show_gets_no_compensation(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);
        $session->booking->package->update([
            'is_trial' => true, 'subject_id' => null, 'teacher_price' => 0,
            'platform_margin_percent' => 0, 'student_price' => 0, 'platform_revenue' => 0,
        ]);
        $session->booking->update(['amount_paid' => 0, 'teacher_amount' => 0, 'platform_amount' => 0, 'margin_percent_snapshot' => 0]);
        $this->logJoin($session, $teacherUser, 'teacher');

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame(ClassSession::OUTCOME_STUDENT_NO_SHOW, $session->attendance_outcome);
        $this->assertNull($session->teacher_compensation_amount);
        $this->assertNoPayoutGenerated($teacher);
    }

    /** قيد chk_packages_price: السعر صفر مسموح لباقة التجربة وحدها، لا لباقة عادية */
    public function test_a_regular_package_cannot_have_a_zero_price(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createEndedSession($teacher);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $session->booking->package->update([
            'teacher_price' => 0, 'platform_margin_percent' => 0, 'student_price' => 0, 'platform_revenue' => 0,
        ]);
    }

    public function test_changing_the_compensation_affects_only_sessions_completed_afterwards(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$first] = $this->createEndedSession($teacher);
        $this->logJoin($first, $teacherUser, 'teacher');
        app(SessionService::class)->completeEndedSessions();

        app(SettingsService::class)->set('student_no_show_teacher_compensation_amount', 15);

        [$second] = $this->createEndedSession($teacher);
        $this->logJoin($second, $teacherUser, 'teacher');
        app(SessionService::class)->completeEndedSessions();

        $this->assertEquals(30, (float) $first->fresh()->teacher_compensation_amount);
        $this->assertEquals(15, (float) $second->fresh()->teacher_compensation_amount);
        $this->assertEquals(45.0, (float) $this->generatePayout($teacher)->gross_amount);
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

        $this->assertNoPayoutGenerated($teacher);
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
        $this->assertEquals(30.0, (float) $this->generatePayout($teacher)->gross_amount);
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

    /** الطالب يُعدّ حاضراً فقط إن دخل خلال أول 20 دقيقة (student_no_show_after_minutes) */
    public function test_a_student_joining_after_the_first_twenty_minutes_counts_as_a_no_show(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin($session, $studentUser, 'student', $session->scheduled_at->clone()->addMinutes(25));

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame(ClassSession::OUTCOME_STUDENT_NO_SHOW, $session->attendance_outcome);
        $this->assertEquals(30, (float) $session->teacher_compensation_amount);
        $this->assertEquals(30.0, (float) $this->generatePayout($teacher)->gross_amount);
    }

    public function test_a_student_joining_within_the_first_twenty_minutes_attends(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher', $session->scheduled_at->clone()->addMinutes(20));
        $this->logJoin($session, $studentUser, 'student', $session->scheduled_at->clone()->addMinutes(20));

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_ATTENDED, $session->fresh()->attendance_outcome);
        $this->assertEquals(100.0, (float) $this->generatePayout($teacher)->gross_amount);
    }

    /** المعلم على نفس المهلة: دخل بعد الدقيقة 20 → غياب المعلم ولا مستحق، ولو حضر الطالب في وقته */
    public function test_a_teacher_joining_after_the_first_twenty_minutes_counts_as_absent(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher', $session->scheduled_at->clone()->addMinutes(25));
        $this->logJoin($session, $studentUser, 'student');

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame(ClassSession::OUTCOME_TEACHER_ABSENT, $session->attendance_outcome);
        $this->assertNull($session->teacher_compensation_amount);
        $this->assertNoPayoutGenerated($teacher);
    }

    /** مهلة المعلم بأول دخول كالطالب: دخل في الدقيقة 10 ثم عاد في الدقيقة 40 حاضر */
    public function test_the_teacher_deadline_is_checked_against_the_first_join_not_the_last(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin(
            $session,
            $teacherUser,
            'teacher',
            $session->scheduled_at->clone()->addMinutes(40),
            firstAt: $session->scheduled_at->clone()->addMinutes(10),
        );
        $this->logJoin($session, $studentUser, 'student');

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_ATTENDED, $session->fresh()->attendance_outcome);
    }

    /** المعلم والطالب كلاهما بعد المهلة → غياب المعلم (يُفحص أولاً)، لا تعويض غياب طلاب */
    public function test_a_late_teacher_and_a_late_student_count_as_teacher_absent(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher', $session->scheduled_at->clone()->addMinutes(30));
        $this->logJoin($session, $studentUser, 'student', $session->scheduled_at->clone()->addMinutes(30));

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_TEACHER_ABSENT, $session->fresh()->attendance_outcome);
        $this->assertNoPayoutGenerated($teacher);
    }

    /** المهلة بأول دخول: طالب دخل في الدقيقة 10 ثم عاد في الدقيقة 40 حاضر */
    public function test_the_deadline_is_checked_against_the_first_join_not_the_last(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin(
            $session,
            $studentUser,
            'student',
            $session->scheduled_at->clone()->addMinutes(40),
            firstAt: $session->scheduled_at->clone()->addMinutes(10),
        );

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_ATTENDED, $session->fresh()->attendance_outcome);
    }

    /** المهلة نفسها للطرفين — رفعها إلى 30 يجعل دخول الدقيقة 25 حضوراً للمعلم والطالب معاً */
    public function test_the_join_deadline_is_configurable(): void
    {
        app(SettingsService::class)->set('student_no_show_after_minutes', 30);

        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher', $session->scheduled_at->clone()->addMinutes(25));
        $this->logJoin($session, $studentUser, 'student', $session->scheduled_at->clone()->addMinutes(25));

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_ATTENDED, $session->fresh()->attendance_outcome);
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

    /** سطر الحضور يبقى بعد انتهاء مهلة الدفع/الإلغاء — دخول المعلم لا يمنحه مستحقاً عن حجز لم يُدفع */
    public function test_a_session_of_an_unpaid_booking_gets_nothing_even_if_both_joined(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser, $attendee] = $this->createEndedSession($teacher);
        $attendee->booking->update(['status' => 'expired']);
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin($session, $studentUser, 'student');

        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(ClassSession::OUTCOME_NO_PAID_ATTENDEE, $session->attendance_outcome);
        $this->assertEquals(0, (float) $session->teacher_payout_percent);
        $this->assertNoPayoutGenerated($teacher);
    }

    /** untracked كانت تُحتسب كاملة — لكن ليس لحجز ملغى */
    public function test_an_untracked_session_of_a_cancelled_booking_gets_nothing(): void
    {
        [$teacher] = $this->createTeacher();
        [$session, , $attendee] = $this->createEndedSession($teacher, SessionJoinLog::trackingStartedAt()->clone()->subDays(3));
        $attendee->booking->update(['status' => 'cancelled']);

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_NO_PAID_ATTENDEE, $session->fresh()->attendance_outcome);
        $this->assertNoPayoutGenerated($teacher, $session->scheduled_at);
    }

    /** جلسة أُغلقت سابقاً كمكتملة ثم أُلغي حجزها — المستحقات نفسها تستبعد الحجز غير المدفوع */
    public function test_payout_ignores_a_booking_that_is_no_longer_paid(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser, $attendee] = $this->createEndedSession($teacher);
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin($session, $studentUser, 'student');
        app(SessionService::class)->completeEndedSessions();

        $attendee->booking->update(['status' => 'cancelled']);

        $this->assertNoPayoutGenerated($teacher);
    }

    /** جلسة مجموعة: حجز مدفوع وآخر انتهت مهلته — يُصرف نصيب المدفوع وحده لا المجموع */
    public function test_a_group_session_pays_only_the_share_of_paid_bookings(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, $studentUser, $paidAttendee] = $this->createEndedSession($teacher);
        $this->addAttendeeWithBooking($session, $paidAttendee->booking, 'expired');
        $this->logJoin($session, $teacherUser, 'teacher');
        $this->logJoin($session, $studentUser, 'student');

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame(ClassSession::OUTCOME_ATTENDED, $session->fresh()->attendance_outcome);
        $this->assertEquals(100.0, (float) $this->generatePayout($teacher)->gross_amount);
    }

    /**
     * المنضم بعد انقضاء أصلية لها تعويضية مسجَّل في الاثنتين — حصته تُصرف عن التعويضية
     * التي حضرها فقط، لا عن الأصلية التي فاتته أيضاً (وإلا تجاوز مستحقه ما دفع).
     */
    public function test_a_late_joiner_share_is_paid_on_the_makeup_only_not_on_the_original_too(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$original, $earlyUser, $earlyAttendee] = $this->createEndedSession($teacher);
        $earlyAttendee->forceFill(['created_at' => $original->scheduled_at->clone()->subDay()])->save();

        $makeup = app(SessionService::class)->createMakeupSession($original);
        $makeup->update(['scheduled_at' => now()->subHour()->subMinutes(5), 'duration_min' => 60]);

        $lateAttendee = $this->addAttendeeWithBooking($original, $earlyAttendee->booking, 'confirmed');
        SessionAttendee::create([
            'class_session_id' => $makeup->id,
            'student_id' => $lateAttendee->student_id,
            'booking_id' => $lateAttendee->booking_id,
            'attendance' => 'registered',
        ]);

        foreach ([$original, $makeup->fresh()] as $session) {
            $this->logJoin($session, $teacherUser, 'teacher');
            $this->logJoin($session, $earlyUser, 'student');
        }

        app(SessionService::class)->completeEndedSessions();
        $this->generatePayout($teacher);

        $this->assertDatabaseHas('payout_items', ['class_session_id' => $original->id, 'amount' => 100]);
        // حجز الأول بجلسة واحدة استهلكها في الأصلية — حصته في التعويضية خارج سقفه، فتبقى حصة المتأخر وحدها
        $this->assertDatabaseHas('payout_items', ['class_session_id' => $makeup->id, 'amount' => 100]);
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
            $this->assertArrayNotHasKey('teacher_compensation_amount', $response->json('data'));
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

    /** لا كشف بلا مستحق موجب — كان يُنشأ كشف فارغ بصفر يتكرر في كل توليد */
    private function assertNoPayoutGenerated(Teacher $teacher, ?Carbon $around = null): void
    {
        try {
            $this->generatePayout($teacher, $around);
            $this->fail('Expected no payout to be generated');
        } catch (ValidationException) {
        }

        $this->assertDatabaseMissing('payouts', ['teacher_id' => $teacher->id]);
    }

    private function logJoin(ClassSession $session, User $user, string $role, ?Carbon $at = null, ?Carbon $firstAt = null): void
    {
        $at ??= $session->scheduled_at->clone()->addMinutes(2);

        SessionJoinLog::create([
            'class_session_id' => $session->id,
            'user_id' => $user->id,
            'role' => $role,
            'student_id' => $role === 'student' ? $user->student->id : null,
            'first_joined_at' => $firstAt ?? $at,
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

    /** طالب ثانٍ في نفس الجلسة بحجز مستقل على نفس الباقة (مستحقه 100) — كما في الباقة الجماعية */
    private function addAttendeeWithBooking(ClassSession $session, Booking $sibling, string $status): SessionAttendee
    {
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        $booking = Booking::create([
            ...$sibling->only(['teacher_id', 'package_id', 'amount_paid', 'teacher_amount', 'platform_amount', 'margin_percent_snapshot', 'sessions_total', 'sessions_remaining']),
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'status' => $status,
        ]);

        return SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);
    }
}
