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
