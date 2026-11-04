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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تسجيل الأدمن حضور المعلم أو أحد الطلاب يدوياً — أثناء الجلسة فقط، بموعد بدئها (فيُعدّ حاضراً في
 * الوقت مهما تأخر التسجيل)، ويُحتسب كحضور طبيعي عند انتهاء الجلسة ومنه المستحقات.
 */
class AdminMarkAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // الجلسات يجب أن تبدأ بعد إنشاء جدول session_join_logs (trackingStartedAt) وإلا تُعدّ untracked
        $this->travel(2)->days();
    }

    public function test_marking_both_parties_during_the_session_counts_as_full_attendance(): void
    {
        [$teacher] = $this->createTeacher();
        [$session, , $attendee] = $this->createSession($teacher, now()->subMinutes(10));

        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'teacher'])->assertOk();
        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'student', 'student_id' => $attendee->student_id])
            ->assertOk()
            ->assertJsonCount(2, 'data.join_logs');

        $this->assertDatabaseHas('session_join_logs', ['class_session_id' => $session->id, 'role' => 'teacher', 'join_count' => 0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.attendance_marked']);

        $this->completeAndAssertAttended($session, $teacher);
    }

    /** بعد مهلة التأخر (20 دقيقة) لا يزال يُعدّ حاضراً في الوقت — الدخول يُكتب بموعد البدء */
    public function test_marking_late_in_the_session_still_counts_as_on_time(): void
    {
        [$teacher] = $this->createTeacher();
        [$session, , $attendee] = $this->createSession($teacher, now()->subMinutes(50));

        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'teacher'])->assertOk();
        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'student', 'student_id' => $attendee->student_id])->assertOk();

        $this->completeAndAssertAttended($session, $teacher);
    }

    /** المعلم دخل فعلاً متأخراً بعد المهلة — تسجيل الأدمن يثبّت دخوله في الوقت ويُبقي عدد دخوله */
    public function test_marking_overrides_a_late_real_join(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session, , $attendee] = $this->createSession($teacher, now()->subMinutes(40));
        $this->logJoin($session, $teacherUser, 'teacher', now()->subMinutes(5));

        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'teacher'])->assertOk();
        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'student', 'student_id' => $attendee->student_id])->assertOk();

        $log = SessionJoinLog::where('class_session_id', $session->id)->where('role', 'teacher')->sole();
        $this->assertSame(1, $log->join_count);

        $this->completeAndAssertAttended($session, $teacher);
    }

    public function test_allowed_in_the_early_join_window(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createSession($teacher, now()->addMinutes(3));

        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'teacher'])->assertOk();
    }

    public function test_rejected_before_the_session(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createSession($teacher, now()->addMinutes(30));

        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'teacher'])->assertStatus(422);
        $this->assertDatabaseCount('session_join_logs', 0);
    }

    public function test_rejected_after_the_session(): void
    {
        [$teacher] = $this->createTeacher();
        [$session, , $attendee] = $this->createSession($teacher, now()->subMinutes(61));

        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'student', 'student_id' => $attendee->student_id])
            ->assertStatus(422);
        $this->assertDatabaseCount('session_join_logs', 0);
    }

    public function test_rejected_for_a_cancelled_session(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createSession($teacher, now()->subMinutes(10));
        $session->update(['status' => 'cancelled']);

        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'teacher'])->assertStatus(422);
    }

    public function test_rejected_for_a_student_not_in_the_session(): void
    {
        [$teacher] = $this->createTeacher();
        [$session] = $this->createSession($teacher, now()->subMinutes(10));
        $stranger = Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']);

        $this->asAdmin()->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'student', 'student_id' => $stranger->id])
            ->assertStatus(422);
    }

    public function test_only_admins_can_mark_attendance(): void
    {
        [$teacher, $teacherUser] = $this->createTeacher();
        [$session] = $this->createSession($teacher, now()->subMinutes(10));

        $this->as($teacherUser->createToken('t')->plainTextToken)
            ->postJson("/api/class-sessions/{$session->id}/attendance", ['role' => 'teacher'])
            ->assertForbidden();
    }

    private function completeAndAssertAttended(ClassSession $session, Teacher $teacher): void
    {
        $this->travelTo($session->scheduled_at->clone()->addMinutes(61));
        app(SessionService::class)->completeEndedSessions();

        $session->refresh();
        $this->assertSame(ClassSession::OUTCOME_ATTENDED, $session->attendance_outcome);
        $this->assertEquals(100, (float) $session->teacher_payout_percent);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());
        $this->assertEquals(100.0, (float) $payout->gross_amount);
    }

    private function asAdmin()
    {
        return $this->as(User::factory()->admin()->create()->createToken('t')->plainTextToken);
    }

    private function logJoin(ClassSession $session, User $user, string $role, Carbon $at): void
    {
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
     * باقة فردية بجلسة واحدة مدتها 60 دقيقة — مستحق المعلم الكامل لها 100.
     *
     * @return array{0: ClassSession, 1: User, 2: SessionAttendee}
     */
    private function createSession(Teacher $teacher, Carbon $scheduledAt): array
    {
        $subject = Subject::create(['code' => 'ma-'.uniqid(), 'name_ar' => 'مادة']);

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
            'scheduled_at' => $scheduledAt,
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
