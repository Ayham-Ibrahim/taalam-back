<?php

namespace Tests\Feature\Notifications;

use App\Models\Booking;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\SessionReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * scheduled_at تُخزَّن دائماً بتوقيت الخادم (UTC) — البريد يجب أن يعرض وقتاً
 * محوَّلاً لمنطقة المستلم نفسه (مدرس أو طالب)، وإلا يظهر وقت مغاير تماماً عمّا
 * اتفقا عليه فعلياً عند الحجز (المدرس والطالب في منطقتين مختلفتين).
 */
class SessionReminderTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_shows_the_time_converted_to_each_recipients_own_timezone(): void
    {
        $subject = Subject::create(['code' => 'tz-'.uniqid(), 'name_ar' => 'مادة']);

        $teacherUser = User::factory()->teacher()->create(['timezone' => 'Europe/London']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $studentUser = User::factory()->student()->create(['timezone' => 'Asia/Tokyo']);
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

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
        $booking = Booking::create([
            'reference' => 'TZ-'.strtoupper(uniqid()),
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

        // 12:00 ظهراً UTC = 12:00 لندن (توقيت شتوي) = 21:00 طوكيو (UTC+9)
        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => Carbon::create(2026, 1, 15, 12, 0, 0, 'UTC'),
            'duration_min' => 60,
            'status' => 'scheduled',
            'join_url_teacher' => 'https://meet.example/teacher',
            'join_url_student' => 'https://meet.example/student',
        ]);

        $notification = new SessionReminder($session, 'https://meet.example/student');

        $teacherMail = $notification->toMail($teacherUser);
        $studentMail = $notification->toMail($studentUser);

        $teacherText = implode(' ', $teacherMail->introLines);
        $studentText = implode(' ', $studentMail->introLines);

        $this->assertStringContainsString('12:00', $teacherText);
        $this->assertStringContainsString('Europe/London', $teacherText);

        $this->assertStringContainsString('21:00', $studentText);
        $this->assertStringContainsString('Asia/Tokyo', $studentText);
    }

    public function test_falls_back_to_server_timezone_when_recipient_has_none(): void
    {
        $subject = Subject::create(['code' => 'tz-'.uniqid(), 'name_ar' => 'مادة']);
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
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
        $booking = Booking::create([
            'reference' => 'TZ-'.strtoupper(uniqid()),
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
            'scheduled_at' => Carbon::create(2026, 1, 15, 12, 0, 0, 'UTC'),
            'duration_min' => 60,
            'status' => 'scheduled',
            'join_url_teacher' => 'https://meet.example/teacher',
            'join_url_student' => 'https://meet.example/student',
        ]);

        $notifiableWithoutTimezone = new User(['name' => 'بلا منطقة زمنية']);
        $notifiableWithoutTimezone->timezone = null;

        $mail = (new SessionReminder($session, 'https://meet.example/student'))->toMail($notifiableWithoutTimezone);

        $this->assertStringContainsString('12:00', implode(' ', $mail->introLines));
    }
}
