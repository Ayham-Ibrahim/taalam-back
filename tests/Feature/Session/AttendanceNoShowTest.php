<?php

namespace Tests\Feature\Session;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceNoShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_absence_with_enough_notice_is_excused_and_not_deducted(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$booking, $session] = $this->createBookingWithSession($teacher, now()->addDay());
        $attendee = $this->attendeeFor($session, $booking);

        $notifiedAt = now(); // أكثر من 6 ساعات قبل الجلسة (اليوم بعد)
        $result = app(SessionService::class)->recordStudentAbsence($attendee, $notifiedAt, 'ظرف عائلي');

        $this->assertSame('excused', $result->attendance);
        $this->assertFalse($result->deducted_from_balance);
        $this->assertSame(0, $booking->fresh()->sessions_used);
        $this->assertSame(4, $booking->fresh()->sessions_remaining);
    }

    public function test_student_absence_without_enough_notice_is_deducted_from_balance(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$booking, $session] = $this->createBookingWithSession($teacher, now()->addHours(2));
        $attendee = $this->attendeeFor($session, $booking);

        $notifiedAt = now(); // أقل من 6 ساعات قبل الجلسة
        $result = app(SessionService::class)->recordStudentAbsence($attendee, $notifiedAt, null);

        $this->assertSame('absent', $result->attendance);
        $this->assertTrue($result->deducted_from_balance);
        $this->assertSame(1, $booking->fresh()->sessions_used);
        $this->assertSame(3, $booking->fresh()->sessions_remaining);
    }

    public function test_absence_without_any_notice_is_treated_as_unnotified(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$booking, $session] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $attendee = $this->attendeeFor($session, $booking);

        $result = app(SessionService::class)->recordStudentAbsence($attendee, null, null);

        $this->assertSame('absent', $result->attendance);
        $this->assertTrue($result->deducted_from_balance);
    }

    public function test_teacher_no_show_excuses_students_and_creates_makeup_session(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$booking, $session] = $this->createBookingWithSession($teacher, now()->addHours(1));
        $attendee = $this->attendeeFor($session, $booking);

        $updated = app(SessionService::class)->recordTeacherNoShow($session, 'لم يحضر المعلم');

        $this->assertSame('no_show_teacher', $updated->status);
        $this->assertSame('excused', $attendee->fresh()->attendance);
        $this->assertFalse((bool) $attendee->fresh()->deducted_from_balance);

        $this->assertDatabaseHas('class_sessions', [
            'makeup_for_session_id' => $session->id,
            'is_makeup' => 1,
            'status' => 'scheduled',
        ]);

        $this->assertSame(1, $teacher->fresh()->no_show_count);
    }

    public function test_teacher_no_show_threshold_triggers_automatic_suspension(): void
    {
        [$teacher] = $this->createVerifiedTeacher();

        for ($i = 0; $i < 3; $i++) {
            [$booking, $session] = $this->createBookingWithSession($teacher, now()->addHours(1)->addDays($i));
            app(SessionService::class)->recordTeacherNoShow($session);
        }

        $this->assertSame(3, $teacher->fresh()->no_show_count);
        $this->assertSame('suspended', $teacher->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'teacher.suspended']);
    }

    /**
     * @return array{0: Teacher}
     */
    private function createVerifiedTeacher(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher];
    }

    /**
     * @return array{0: Booking, 1: ClassSession}
     */
    private function createBookingWithSession(Teacher $teacher, Carbon $scheduledAt): array
    {
        $subject = Subject::create(['code' => 'ns-'.uniqid(), 'name_ar' => 'مادة']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
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
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        return [$booking, $session];
    }

    private function attendeeFor(ClassSession $session, Booking $booking): SessionAttendee
    {
        return SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);
    }
}
