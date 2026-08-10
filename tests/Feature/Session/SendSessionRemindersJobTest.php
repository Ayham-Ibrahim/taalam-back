<?php

namespace Tests\Feature\Session;

use App\Jobs\SendSessionRemindersJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\SessionReminder;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendSessionRemindersJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_reminder_window_is_two_hours(): void
    {
        $this->seed(SettingsSeeder::class);

        $this->assertSame(120, app(SettingsService::class)->get('session_reminder_minutes_before'));
    }

    public function test_sends_reminder_to_teacher_and_student_within_the_window_and_marks_it_sent(): void
    {
        $this->seed(SettingsSeeder::class);
        Notification::fake();

        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [, $studentUser, $session] = $this->createConfirmedUpcomingSession($teacher, minutesUntilStart: 90);

        app(SendSessionRemindersJob::class)->handle(app(SettingsService::class), app(\App\Services\NotificationService::class));

        Notification::assertSentTo($teacherUser, SessionReminder::class);
        Notification::assertSentTo($studentUser, SessionReminder::class);
        $this->assertNotNull($session->fresh()->reminder_sent_at);
    }

    public function test_does_not_send_for_a_session_outside_the_window(): void
    {
        $this->seed(SettingsSeeder::class);
        Notification::fake();

        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [, $studentUser, $session] = $this->createConfirmedUpcomingSession($teacher, minutesUntilStart: 180);

        app(SendSessionRemindersJob::class)->handle(app(SettingsService::class), app(\App\Services\NotificationService::class));

        Notification::assertNotSentTo($teacherUser, SessionReminder::class);
        Notification::assertNotSentTo($studentUser, SessionReminder::class);
        $this->assertNull($session->fresh()->reminder_sent_at);
    }

    public function test_does_not_send_for_a_session_without_a_join_url_yet_unpaid_or_bbb_not_ready(): void
    {
        $this->seed(SettingsSeeder::class);
        Notification::fake();

        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [, $studentUser, ] = $this->createConfirmedUpcomingSession($teacher, minutesUntilStart: 90, withJoinUrls: false);

        app(SendSessionRemindersJob::class)->handle(app(SettingsService::class), app(\App\Services\NotificationService::class));

        Notification::assertNotSentTo($teacherUser, SessionReminder::class);
        Notification::assertNotSentTo($studentUser, SessionReminder::class);
    }

    public function test_does_not_send_twice_for_the_same_session(): void
    {
        $this->seed(SettingsSeeder::class);
        Notification::fake();

        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [, $studentUser, ] = $this->createConfirmedUpcomingSession($teacher, minutesUntilStart: 90);

        $job = SendSessionRemindersJob::class;
        app($job)->handle(app(SettingsService::class), app(\App\Services\NotificationService::class));
        app($job)->handle(app(SettingsService::class), app(\App\Services\NotificationService::class));

        Notification::assertSentToTimes($teacherUser, SessionReminder::class, 1);
        Notification::assertSentToTimes($studentUser, SessionReminder::class, 1);
    }

    /**
     * @return array{0: Teacher, 1: User}
     */
    private function createVerifiedTeacher(): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher, $user];
    }

    /**
     * @return array{0: Student, 1: User, 2: ClassSession}
     */
    private function createConfirmedUpcomingSession(Teacher $teacher, int $minutesUntilStart, bool $withJoinUrls = true): array
    {
        $subject = Subject::create(['code' => 'rmd-'.uniqid(), 'name_ar' => 'مادة']);

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
            'scheduled_at' => now()->addMinutes($minutesUntilStart),
            'duration_min' => 60,
            'status' => 'scheduled',
            'join_url_teacher' => $withJoinUrls ? 'https://meet.example/teacher-x' : null,
            'join_url_student' => $withJoinUrls ? 'https://meet.example/student-x' : null,
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return [$student, $studentUser, $session];
    }
}
