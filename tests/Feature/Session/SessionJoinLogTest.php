<?php

namespace Tests\Feature\Session;

use App\Jobs\SendSessionRemindersJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\SessionJoinLog;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\SessionReminder;
use App\Services\BigBlueButtonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * سجل الانضمام (session_join_logs) يُكتب عند نجاح GET /class-sessions/{id}/join ويُعرض
 * للأدمن فقط — دون أي أثر على حالة الجلسة أو session_attendees أو رصيد الحجز.
 */
class SessionJoinLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_joins_are_logged_for_the_teacher_and_the_student_only(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser, $attendee] = $this->createBookingWithSession($teacher, now()->subMinutes(5));
        $this->mockRoomExists();

        $admin = User::factory()->admin()->create();
        $this->joinAs($admin, $session)->assertOk();
        $this->joinAs($teacherUser, $session)->assertOk();
        $this->joinAs($studentUser, $session)->assertOk();
        $this->joinAs($studentUser, $session)->assertOk();

        $this->assertSame(2, SessionJoinLog::count());
        $this->assertDatabaseHas('session_join_logs', ['class_session_id' => $session->id, 'user_id' => $teacherUser->id, 'role' => 'teacher', 'join_count' => 1]);
        $this->assertDatabaseHas('session_join_logs', [
            'class_session_id' => $session->id,
            'user_id' => $studentUser->id,
            'role' => 'student',
            'student_id' => $booking->student_id,
            'join_count' => 2,
        ]);

        // لا أثر على أي بيانات أخرى
        $this->assertSame('scheduled', $session->fresh()->status);
        $this->assertNull($session->fresh()->started_at);
        $this->assertSame('registered', $attendee->fresh()->attendance);
        $this->assertNull($attendee->fresh()->joined_at);
        $this->assertSame(0, $booking->fresh()->sessions_used);
    }

    public function test_a_rejected_join_is_not_logged(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->addHour());

        $this->joinAs($studentUser, $session)->assertStatus(422);

        $this->assertSame(0, SessionJoinLog::count());
    }

    public function test_join_logs_are_exposed_to_the_admin_only(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->addMinutes(2));
        $this->mockRoomExists();
        $this->joinAs($studentUser, $session)->assertOk();

        $admin = User::factory()->admin()->create();
        $adminList = $this->as($admin->createToken('t')->plainTextToken)->getJson("/api/class-sessions?teacher_id={$teacher->id}");
        $this->assertSame('student', $adminList->json('data.0.join_logs.0.role'));
        $this->assertTrue($adminList->json('data.0.join_tracked'));
        $adminShow = $this->as($admin->createToken('t')->plainTextToken)->getJson("/api/class-sessions/{$session->id}");
        $this->assertCount(1, $adminShow->json('data.join_logs'));

        $teacherList = $this->as($teacherUser->createToken('t')->plainTextToken)->getJson('/api/class-sessions');
        $this->assertArrayNotHasKey('join_logs', $teacherList->json('data.0'));
        $this->assertArrayNotHasKey('join_tracked', $teacherList->json('data.0'));
        $studentShow = $this->as($studentUser->createToken('t')->plainTextToken)->getJson("/api/class-sessions/{$session->id}");
        $this->assertArrayNotHasKey('join_logs', $studentShow->json('data'));
    }

    public function test_the_signed_email_link_logs_the_join_and_redirects_to_bbb(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subMinutes(5));
        $this->mockRoomExists();

        $this->get($this->signedJoinLink($session, $studentUser))->assertRedirect($session->join_url_student);

        $this->assertDatabaseHas('session_join_logs', ['class_session_id' => $session->id, 'user_id' => $studentUser->id, 'role' => 'student']);
    }

    public function test_the_email_link_shows_a_page_and_logs_nothing_outside_the_join_window(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->addHour());

        $this->get($this->signedJoinLink($session, $studentUser))->assertStatus(422)->assertSee('لم تبدأ');

        $this->assertSame(0, SessionJoinLog::count());
    }

    public function test_the_email_link_rejects_an_unsigned_url_or_a_user_not_in_the_session(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subMinutes(5));
        $outsider = User::factory()->student()->create();

        $this->get("/api/class-sessions/{$session->id}/join-redirect/{$studentUser->id}")->assertForbidden();
        $this->get($this->signedJoinLink($session, $outsider))->assertForbidden();

        $this->assertSame(0, SessionJoinLog::count());
    }

    public function test_the_reminder_email_button_goes_through_the_platform(): void
    {
        Notification::fake();

        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->addMinutes(30));

        app()->call([new SendSessionRemindersJob, 'handle']);

        foreach ([$studentUser, $teacherUser] as $recipient) {
            Notification::assertSentTo($recipient, SessionReminder::class, function (SessionReminder $notification) use ($recipient, $session) {
                $url = $notification->toMail($recipient)->actionUrl;

                return str_contains($url, "/class-sessions/{$session->id}/join-redirect/{$recipient->id}")
                    && str_contains($url, 'signature=');
            });
        }
    }

    private function signedJoinLink(ClassSession $session, User $user): string
    {
        return URL::temporarySignedRoute('class-sessions.join-redirect', now()->addHours(2), ['session' => $session->id, 'user' => $user->id]);
    }

    private function joinAs(User $user, ClassSession $session)
    {
        return $this->as($user->createToken('t')->plainTextToken)->getJson("/api/class-sessions/{$session->id}/join");
    }

    private function mockRoomExists(): void
    {
        $this->mock(BigBlueButtonService::class, fn ($mock) => $mock->shouldReceive('meetingExists')->andReturn(true));
    }

    private function createVerifiedTeacher(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher, $teacherUser];
    }

    /**
     * @return array{0: Booking, 1: ClassSession, 2: User, 3: SessionAttendee}
     */
    private function createBookingWithSession(Teacher $teacher, Carbon $scheduledAt): array
    {
        $subject = Subject::create(['code' => 'sj-'.uniqid(), 'name_ar' => 'مادة']);

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
            'bbb_meeting_id' => (string) Str::uuid(),
            'bbb_attendee_pw' => 'attendee-pw',
            'bbb_moderator_pw' => 'moderator-pw',
            'join_url_teacher' => 'https://meet.example/teacher-x',
            'join_url_student' => 'https://meet.example/student-x',
        ]);

        $attendee = SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return [$booking, $session, $studentUser, $attendee];
    }
}
