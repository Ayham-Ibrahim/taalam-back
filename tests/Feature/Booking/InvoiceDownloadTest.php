<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_download_a_pdf_invoice_for_their_paid_booking(): void
    {
        [$student, $studentUser, $booking] = $this->createPaidBooking();

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->get("/api/bookings/{$booking->id}/invoice");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_teacher_can_download_the_same_bookings_invoice(): void
    {
        [$student, $studentUser, $booking, $teacher, $teacherUser] = $this->createPaidBooking();

        $response = $this->as($teacherUser->createToken('t')->plainTextToken)
            ->get("/api/bookings/{$booking->id}/invoice");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_another_student_cannot_download_someone_elses_invoice(): void
    {
        [, , $booking] = $this->createPaidBooking();

        $otherStudentUser = User::factory()->student()->create();
        Student::create(['user_id' => $otherStudentUser->id, 'education_type' => 'school']);

        $response = $this->as($otherStudentUser->createToken('t')->plainTextToken)
            ->get("/api/bookings/{$booking->id}/invoice");

        $response->assertStatus(403);
    }

    public function test_downloading_an_invoice_for_an_unpaid_booking_is_rejected(): void
    {
        [$student, $studentUser, $booking] = $this->createPaidBooking(paid: false);

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/bookings/{$booking->id}/invoice");

        $response->assertStatus(422);
    }

    public function test_student_can_download_a_pdf_invoice_for_their_paid_enrollment(): void
    {
        $subject = Subject::create(['code' => 'inv-'.uniqid(), 'name_ar' => 'مادة']);
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'training_center', 'status' => 'verified']);
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        $field = \App\Models\CourseField::create(['code' => 'inv-field-'.uniqid(), 'name_ar' => 'مجال']);
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title' => 'دورة',
            'course_field_id' => $field->id,
            'subject_id' => $subject->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'total_sessions' => 10,
            'session_duration_min' => 60,
            'max_seats' => 20,
            'pricing_mode' => 'total',
            'provider_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);
        $enrollment = Enrollment::create([
            'reference' => 'ENR-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'amount_paid' => 160,
            'provider_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
        Payment::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'amount' => 160,
            'provider_amount' => 100,
            'platform_amount' => 60,
            'currency' => 'USD',
            'method' => 'stripe',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->get("/api/enrollments/{$enrollment->id}/invoice");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * @return array{0: Student, 1: User, 2: Booking, 3: Teacher, 4: User}
     */
    private function createPaidBooking(bool $paid = true): array
    {
        $subject = Subject::create(['code' => 'inv-'.uniqid(), 'name_ar' => 'مادة']);
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
            'reference' => 'INV-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 1,
            'sessions_remaining' => 1,
            'status' => $paid ? 'confirmed' : 'pending_payment',
            'confirmed_at' => $paid ? now() : null,
        ]);

        if ($paid) {
            Payment::create([
                'booking_id' => $booking->id,
                'student_id' => $student->id,
                'amount' => 160,
                'provider_amount' => 100,
                'platform_amount' => 60,
                'currency' => 'USD',
                'method' => 'stripe',
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }

        return [$student, $studentUser, $booking, $teacher, $teacherUser];
    }
}
