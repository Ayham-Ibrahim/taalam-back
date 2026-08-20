<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ExpireStaleBookingsJob;
use App\Models\Booking;
use App\Models\Course;
use App\Models\CourseField;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireStaleBookingsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_expires_only_bookings_and_enrollments_past_their_hold_deadline(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        $subject = Subject::create(['code' => 'x1', 'name_ar' => 'مادة']);

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
        ]);

        $expiredBooking = Booking::create([
            'reference' => 'BK-EXPIRED1',
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'pending_payment',
            'hold_expires_at' => now()->subMinutes(5),
        ]);

        $freshBooking = Booking::create([
            'reference' => 'BK-FRESH1',
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'pending_payment',
            'hold_expires_at' => now()->addMinutes(10),
        ]);

        $expiredPendingTeacherConfirmation = Booking::create([
            'reference' => 'BK-REQ-EXPIRED1',
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'pending_teacher_confirmation',
            'requested_date' => now()->subDay()->toDateString(),
            'requested_start_time' => '10:00',
            'requested_timezone' => 'UTC',
        ]);

        $freshPendingTeacherConfirmation = Booking::create([
            'reference' => 'BK-REQ-FRESH1',
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'pending_teacher_confirmation',
            'requested_date' => now()->addDay()->toDateString(),
            'requested_start_time' => '10:00',
            'requested_timezone' => 'UTC',
        ]);

        $center = Teacher::create([
            'user_id' => User::factory()->teacher()->create()->id,
            'teacher_type' => 'training_center',
            'status' => 'verified',
        ]);
        $field = CourseField::create(['code' => 'f1', 'name_ar' => 'مجال']);
        $course = Course::create([
            'teacher_id' => $center->id,
            'title' => 'دورة',
            'course_field_id' => $field->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'total_sessions' => 4,
            'max_seats' => 10,
            'provider_price' => 100,
            'platform_margin_percent' => 50,
            'student_price' => 150,
            'platform_revenue' => 50,
            'status' => 'active',
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);

        $expiredEnrollment = Enrollment::create([
            'reference' => 'EN-EXPIRED1',
            'student_id' => $student->id,
            'course_id' => $course->id,
            'teacher_id' => $center->id,
            'amount_paid' => 150,
            'provider_amount' => 100,
            'platform_amount' => 50,
            'margin_percent_snapshot' => 50,
            'status' => 'pending_payment',
            'hold_expires_at' => now()->subMinutes(5),
        ]);

        (new ExpireStaleBookingsJob)->handle(app(\App\Services\BookingService::class));

        $this->assertSame('expired', $expiredBooking->fresh()->status);
        $this->assertSame('pending_payment', $freshBooking->fresh()->status);
        $this->assertSame('expired', $expiredPendingTeacherConfirmation->fresh()->status);
        $this->assertSame('انتهى وقت الجلسة المقترحة دون موافقة المعلم.', $expiredPendingTeacherConfirmation->fresh()->cancellation_reason);
        $this->assertSame('pending_teacher_confirmation', $freshPendingTeacherConfirmation->fresh()->status);
        $this->assertSame('cancelled', $expiredEnrollment->fresh()->status);
    }
}
