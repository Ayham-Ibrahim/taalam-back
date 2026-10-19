<?php

namespace Tests\Feature\Session;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\RescheduleRequest;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\SessionRescheduledByAdmin;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminRescheduleSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_changes_session_time_without_touching_the_join_links(): void
    {
        Notification::fake();

        [$teacher, $session, $students] = $this->createSessionWithStudents(now()->addDays(3));
        app(SessionService::class)->createBbbRoom($session);
        $session->update(['reminder_sent_at' => now()]);
        $before = $session->fresh();

        $newTime = now()->addDays(4)->startOfMinute();

        $response = $this->as($this->adminToken())->patchJson("/api/class-sessions/{$session->id}/schedule", [
            'scheduled_at' => $newTime->toIso8601String(),
            'notes' => 'بطلب من ولي الأمر',
        ]);

        $response->assertOk();

        $after = $session->fresh();
        $this->assertSame($newTime->toDateTimeString(), $after->scheduled_at->toDateTimeString());
        $this->assertSame($before->scheduled_at->toDateTimeString(), $after->original_scheduled_at->toDateTimeString());
        $this->assertNull($after->reminder_sent_at);
        $this->assertSame('scheduled', $after->status);

        $this->assertSame($before->bbb_meeting_id, $after->bbb_meeting_id);
        $this->assertSame($before->join_url_student, $after->join_url_student);
        $this->assertSame($before->join_url_teacher, $after->join_url_teacher);

        $this->assertDatabaseHas('audit_logs', ['action' => 'session.rescheduled_by_admin']);
        Notification::assertSentTo($teacher->user, SessionRescheduledByAdmin::class);
        Notification::assertSentTo($students[0]->user, SessionRescheduledByAdmin::class);
    }

    public function test_iso_time_with_offset_is_stored_in_utc(): void
    {
        Notification::fake();

        [, $session] = $this->createSessionWithStudents(now()->addDays(3));
        $local = now()->addDays(5)->setTimezone('Asia/Damascus')->setTime(18, 0);

        $this->as($this->adminToken())->patchJson("/api/class-sessions/{$session->id}/schedule", [
            'scheduled_at' => $local->toIso8601String(),
        ])->assertOk();

        $this->assertSame(
            $local->copy()->setTimezone('UTC')->toDateTimeString(),
            $session->fresh()->scheduled_at->toDateTimeString(),
        );
    }

    public function test_non_admin_cannot_change_session_time(): void
    {
        [$teacher, $session] = $this->createSessionWithStudents(now()->addDays(3));
        $teacherToken = $teacher->user->createToken('t')->plainTextToken;

        $this->as($teacherToken)->patchJson("/api/class-sessions/{$session->id}/schedule", [
            'scheduled_at' => now()->addDays(4)->toIso8601String(),
        ])->assertForbidden();
    }

    public function test_new_time_in_the_past_is_rejected(): void
    {
        [, $session] = $this->createSessionWithStudents(now()->addDays(3));

        $this->as($this->adminToken())->patchJson("/api/class-sessions/{$session->id}/schedule", [
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_session_that_already_started_or_ended_cannot_be_moved(): void
    {
        [, $started] = $this->createSessionWithStudents(now()->subMinutes(10));
        [, $completed] = $this->createSessionWithStudents(now()->addDays(2));
        $completed->update(['status' => 'completed']);

        $token = $this->adminToken();

        foreach ([$started, $completed] as $session) {
            $this->as($token)->patchJson("/api/class-sessions/{$session->id}/schedule", [
                'scheduled_at' => now()->addDays(5)->toIso8601String(),
            ])->assertStatus(422);
        }
    }

    public function test_session_with_recorded_attendance_cannot_be_moved(): void
    {
        [, $session] = $this->createSessionWithStudents(now()->addDays(3));
        $session->attendees()->update(['attendance' => 'absent', 'deducted_from_balance' => true]);

        $this->as($this->adminToken())->patchJson("/api/class-sessions/{$session->id}/schedule", [
            'scheduled_at' => now()->addDays(4)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_excused_absence_for_the_old_time_is_reset(): void
    {
        Notification::fake();

        [, $session] = $this->createSessionWithStudents(now()->addDays(3));
        $session->attendees()->update([
            'attendance' => 'excused',
            'absence_notified_at' => now(),
            'absence_reason' => 'سفر',
        ]);

        $this->as($this->adminToken())->patchJson("/api/class-sessions/{$session->id}/schedule", [
            'scheduled_at' => now()->addDays(4)->toIso8601String(),
        ])->assertOk();

        $attendee = $session->attendees()->first();
        $this->assertSame('registered', $attendee->attendance);
        $this->assertNull($attendee->absence_notified_at);
    }

    public function test_conflict_with_another_student_of_a_group_session_is_rejected(): void
    {
        [, $session, $students] = $this->createSessionWithStudents(now()->addDays(3), studentsCount: 2, format: 'group');
        $newTime = now()->addDays(4)->startOfHour();

        // الطالب الثاني (لا صاحب الحجز) لديه جلسة أخرى مع معلم آخر في نفس الموعد الجديد.
        [, $otherSession] = $this->createSessionWithStudents($newTime);
        SessionAttendee::create([
            'class_session_id' => $otherSession->id,
            'student_id' => $students[1]->id,
            'booking_id' => $otherSession->booking_id,
            'attendance' => 'registered',
        ]);

        $this->as($this->adminToken())->patchJson("/api/class-sessions/{$session->id}/schedule", [
            'scheduled_at' => $newTime->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_pending_reschedule_request_is_closed_after_admin_change(): void
    {
        Notification::fake();

        [$teacher, $session] = $this->createSessionWithStudents(now()->addDays(3));
        $request = RescheduleRequest::create([
            'class_session_id' => $session->id,
            'booking_id' => $session->booking_id,
            'requested_by' => $teacher->user_id,
            'requester_role' => 'teacher',
            'current_scheduled_at' => $session->scheduled_at,
            'proposed_scheduled_at' => now()->addDays(6),
            'within_free_window' => false,
            'hours_before_session' => 72,
            'reason' => 'سبب',
            'status' => 'pending',
            'sla_due_at' => now()->addHours(12),
        ]);

        $this->as($this->adminToken())->patchJson("/api/class-sessions/{$session->id}/schedule", [
            'scheduled_at' => now()->addDays(4)->toIso8601String(),
        ])->assertOk();

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_admin_can_list_sessions_of_one_teacher(): void
    {
        [$teacher, $session] = $this->createSessionWithStudents(now()->addDays(3));
        $this->createSessionWithStudents(now()->addDays(3)); // جلسة معلم آخر

        $response = $this->as($this->adminToken())->getJson("/api/class-sessions?teacher_id={$teacher->id}");

        $response->assertOk();
        $this->assertSame([$session->id], collect($response->json('data'))->pluck('id')->all());
    }

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('t')->plainTextToken;
    }

    /**
     * @return array{0: Teacher, 1: ClassSession, 2: array<int, Student>}
     */
    private function createSessionWithStudents(Carbon $scheduledAt, int $studentsCount = 1, string $format = 'individual'): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $subject = Subject::create(['code' => 'ar-'.uniqid(), 'name_ar' => 'مادة']);

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

        $session = null;
        $students = [];

        for ($i = 0; $i < $studentsCount; $i++) {
            $student = Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']);
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

            $session ??= $booking->sessions()->create([
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

            $students[] = $student;
        }

        return [$teacher, $session, $students];
    }
}
