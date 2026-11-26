<?php

namespace Tests\Feature\Session;

use App\Jobs\CreateBbbRoomJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BigBlueButtonService;
use App\Services\BookingService;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * مسارات كانت تترك جلسات مدفوعة بلا غرفة BBB (فلا رابط انضمام ولا تذكير)، بينما
 * يحوّلها completeEndedSessions إلى completed بعد موعدها فتُحتسب ضمن المستحقات.
 */
class SessionRoomCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paying_later_group_student_creates_rooms_for_sessions_owned_by_first_booking(): void
    {
        Queue::fake();

        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'group');

        // أول طالب انضمّ "يملك" جلسات المجموعة (class_sessions.booking_id) لكنه لم يدفع أبداً.
        $firstBooking = $this->createBooking($package, $this->createStudent(), 'expired');
        $session = $firstBooking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->addDays(2),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        $secondStudent = $this->createStudent();
        $secondBooking = $this->createBooking($package, $secondStudent, 'pending_payment');
        $this->attend($session, $secondStudent, $secondBooking);

        app(BookingService::class)->confirmBooking($secondBooking);

        Queue::assertPushed(CreateBbbRoomJob::class, fn ($job) => $job->classSessionId === $session->id);
    }

    public function test_makeup_session_copies_attendees_and_gets_a_room(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();
        $booking = $this->createBooking($this->createPackage($teacher, 'individual'), $student, 'confirmed');
        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->addHour(),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);
        $this->attend($session, $student, $booking);
        app(SessionService::class)->createBbbRoom($session);

        app(SessionService::class)->recordTeacherNoShow($session->fresh());

        $makeup = ClassSession::where('makeup_for_session_id', $session->id)->firstOrFail();

        $this->assertDatabaseHas('session_attendees', [
            'class_session_id' => $makeup->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);
        $this->assertNotNull($makeup->fresh()->join_url_student);
        $this->assertNotSame($session->fresh()->bbb_meeting_id, $makeup->fresh()->bbb_meeting_id);
    }

    /** سطر حضور الحجز المنتهي يبقى على الأصلية — لا يُنسخ للتعويضية */
    public function test_makeup_session_copies_only_paid_attendees(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');
        $paidStudent = $this->createStudent();
        $paidBooking = $this->createBooking($package, $paidStudent, 'confirmed');
        $expiredStudent = $this->createStudent();
        $expiredBooking = $this->createBooking($package, $expiredStudent, 'expired');
        $session = $paidBooking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->addHour(),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);
        $this->attend($session, $paidStudent, $paidBooking);
        $this->attend($session, $expiredStudent, $expiredBooking);

        $makeup = app(SessionService::class)->createMakeupSession($session);

        $this->assertSame([$paidBooking->id], $makeup->attendees()->pluck('booking_id')->all());
    }

    public function test_create_missing_rooms_only_targets_paid_upcoming_sessions(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');

        $paid = $this->sessionFor($teacher, $package, 'confirmed', now()->addDay());
        $unpaid = $this->sessionFor($teacher, $package, 'pending_payment', now()->addDays(2));
        $ended = $this->sessionFor($teacher, $package, 'confirmed', now()->subHours(3));

        $created = app(SessionService::class)->createMissingRooms();

        $this->assertSame(1, $created);
        $this->assertNotNull($paid->fresh()->join_url_student);
        $this->assertNull($unpaid->fresh()->bbb_meeting_id);
        $this->assertNull($ended->fresh()->bbb_meeting_id);
    }

    public function test_prewarm_creates_missing_rooms_only_for_paid_sessions_starting_soon_or_in_progress(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');

        $soon = $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addMinutes(8)));
        $inProgress = $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->subMinutes(20)));
        $alreadyUp = $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addMinutes(3)));
        $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addHour()));
        $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->subHours(2)));
        $this->withRoom($this->sessionFor($teacher, $package, 'pending_payment', now()->addMinutes(5)));

        $bbb = $this->mock(BigBlueButtonService::class);
        $bbb->shouldReceive('meetingExists')->andReturnUsing(fn (string $id) => $id === $alreadyUp->bbb_meeting_id);
        $bbb->shouldReceive('createMeeting')
            ->twice()
            ->withArgs(function (string $id, string $name, string $attendeePw, string $moderatorPw, int $duration, int $expireIfNoUserJoined) use ($soon, $inProgress) {
                // الغرفة تبقى حتى نهاية الجلسة وإن لم يدخلها أحد بعد.
                return in_array($id, [$soon->bbb_meeting_id, $inProgress->bbb_meeting_id], true)
                    && $expireIfNoUserJoined === $duration && $expireIfNoUserJoined > 0;
            })
            ->andReturn(true);

        $this->assertSame(2, app(SessionService::class)->prewarmUpcomingRooms());
    }

    /**
     * انضمّ إليها أحد ثم اختفت غرفتها (أنهاها المعلم، أو خرج الجميع) — لا يعيد التجهيز
     * فتحها تلقائياً؛ أول ضغطة "انضمام" تعيد إنشاءها عبر resolveJoinUrl.
     */
    public function test_prewarm_skips_sessions_someone_already_joined(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');

        $joined = $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->subMinutes(20)));
        $joined->joinLogs()->create([
            'user_id' => $teacher->user_id,
            'role' => 'teacher',
            'first_joined_at' => now()->subMinutes(20),
            'last_joined_at' => now()->subMinutes(20),
        ]);
        $fresh = $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addMinutes(5)));

        $bbb = $this->mock(BigBlueButtonService::class);
        $bbb->shouldReceive('meetingExists')->andReturn(false);
        $bbb->shouldReceive('createMeeting')
            ->once()
            ->withArgs(fn (string $id) => $id === $fresh->bbb_meeting_id)
            ->andReturn(true);

        $this->assertSame(1, app(SessionService::class)->prewarmUpcomingRooms());
    }

    /** انضمام مبكر ثم نُقل الموعد: السجل القديم لا يمنع تجهيز الغرفة في الموعد الجديد. */
    public function test_prewarm_ignores_joins_from_before_a_reschedule(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');

        $rescheduled = $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addMinutes(5)));
        $rescheduled->joinLogs()->create([
            'user_id' => $teacher->user_id,
            'role' => 'teacher',
            'first_joined_at' => now()->subDay(),
            'last_joined_at' => now()->subDay(),
        ]);

        $bbb = $this->mock(BigBlueButtonService::class);
        $bbb->shouldReceive('meetingExists')->andReturn(false);
        $bbb->shouldReceive('createMeeting')->once()->andReturn(true);

        $this->assertSame(1, app(SessionService::class)->prewarmUpcomingRooms());
    }

    /**
     * BBB معلّق: فشل create لجلستين متتاليتين يوقف الدورة بدل انتظار مهلة كل جلسة
     * (worker وحيد بـ --timeout=90 والتذكيرات خلفه في الطابور).
     */
    public function test_prewarm_stops_early_after_two_consecutive_create_failures(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');

        foreach ([2, 3, 4, 5] as $minutes) {
            $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addMinutes($minutes)));
        }

        $bbb = $this->mock(BigBlueButtonService::class);
        $bbb->shouldReceive('meetingExists')->andReturn(false);
        $bbb->shouldReceive('createMeeting')->twice()->andReturn(false);
        $bbb->shouldReceive('isReachable')->once()->andReturn(false);

        $this->assertSame(0, app(SessionService::class)->prewarmUpcomingRooms());
    }

    /**
     * BBB يستجيب لكن create فشل لأقدم جلستين لسبب يخصهما — لا تمنعان تجهيز ما بعدهما
     * (مرتبتان أولاً في كل دورة، فكان التوقف يتكرر كل دقيقة حتى ينتهي موعدهما).
     */
    public function test_prewarm_continues_past_session_specific_failures_while_bbb_is_reachable(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');

        foreach ([2, 3, 4, 5] as $minutes) {
            $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addMinutes($minutes)));
        }

        $bbb = $this->mock(BigBlueButtonService::class);
        $bbb->shouldReceive('meetingExists')->andReturn(false);
        $bbb->shouldReceive('createMeeting')->times(4)->andReturn(false, false, true, true);
        $bbb->shouldReceive('isReachable')->once()->andReturn(true);

        $this->assertSame(2, app(SessionService::class)->prewarmUpcomingRooms());
    }

    /** BBB بطيء لكنه ينجح: لا تبدأ الدورة جلسة جديدة بعد 45 ثانية (worker بـ --timeout=90). */
    public function test_prewarm_stops_starting_new_sessions_after_its_time_budget(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');

        foreach ([2, 3, 4, 5] as $minutes) {
            $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addMinutes($minutes)));
        }

        $this->freezeTime();

        $bbb = $this->mock(BigBlueButtonService::class);
        $bbb->shouldReceive('meetingExists')->andReturn(false);
        $bbb->shouldReceive('createMeeting')->twice()->andReturnUsing(function () {
            $this->travel(25)->seconds();

            return true;
        });

        $this->assertSame(2, app(SessionService::class)->prewarmUpcomingRooms());
    }

    public function test_prewarm_resets_the_failure_count_after_a_success(): void
    {
        $teacher = $this->createTeacher();
        $package = $this->createPackage($teacher, 'individual');

        foreach ([2, 3, 4, 5] as $minutes) {
            $this->withRoom($this->sessionFor($teacher, $package, 'confirmed', now()->addMinutes($minutes)));
        }

        $bbb = $this->mock(BigBlueButtonService::class);
        $bbb->shouldReceive('meetingExists')->andReturn(false);
        $bbb->shouldReceive('createMeeting')->times(4)->andReturn(false, true, false, true);

        $this->assertSame(2, app(SessionService::class)->prewarmUpcomingRooms());
    }

    public function test_prewarm_job_is_unique_and_never_retried(): void
    {
        $job = new \App\Jobs\PrewarmBbbRoomsJob;

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(300, $job->uniqueFor);
    }

    private function withRoom(ClassSession $session): ClassSession
    {
        $session->update([
            'bbb_meeting_id' => 'meeting-'.$session->id,
            'bbb_attendee_pw' => 'ap',
            'bbb_moderator_pw' => 'mp',
            'join_url_student' => 'https://bbb.example.com/join?s',
            'join_url_teacher' => 'https://bbb.example.com/join?t',
        ]);

        return $session;
    }

    private function sessionFor(Teacher $teacher, Package $package, string $bookingStatus, Carbon $scheduledAt): ClassSession
    {
        $student = $this->createStudent();
        $booking = $this->createBooking($package, $student, $bookingStatus);
        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);
        $this->attend($session, $student, $booking);

        return $session;
    }

    private function createTeacher(): Teacher
    {
        $user = User::factory()->teacher()->create();

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);
    }

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create();

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function createPackage(Teacher $teacher, string $format): Package
    {
        $subject = Subject::create(['code' => 'rc-'.uniqid(), 'name_ar' => 'مادة']);

        return Package::create([
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
    }

    private function createBooking(Package $package, Student $student, string $status): Booking
    {
        return Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $package->teacher_id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => $status,
        ]);
    }

    private function attend(ClassSession $session, Student $student, Booking $booking): SessionAttendee
    {
        return SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);
    }
}
