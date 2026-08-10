<?php

namespace Tests\Feature\Enrollment;

use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseField;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CourseEnrollmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_generates_sessions_for_the_full_course_date_range(): void
    {
        [$center] = $this->createVerifiedCenter();

        // الاثنين والأربعاء لمدة أسبوعين بالضبط = 4 جلسات
        $start = Carbon::now()->next(1)->startOfDay();
        $end = $start->copy()->addDays(13);

        $course = $this->createActiveCourse($center, teacherPrice: 200, margin: 50, start: $start, end: $end);
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);
        $course->schedules()->create(['day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '10:00']);

        $student = $this->createStudent();

        $enrollment = app(EnrollmentService::class)->initiateEnrollment($student, $course);

        $this->assertSame('pending_payment', $enrollment->status);
        $this->assertEquals(300.0, (float) $enrollment->amount_paid);
        $this->assertEquals(200.0, (float) $enrollment->provider_amount);
        $this->assertEquals(100.0, (float) $enrollment->platform_amount);

        $this->assertEqualsWithDelta(
            (float) $enrollment->provider_amount + (float) $enrollment->platform_amount,
            (float) $enrollment->amount_paid,
            0.001,
        );

        $this->assertSame(4, ClassSession::where('course_id', $course->id)->count());

        $this->assertDatabaseHas('payments', [
            'enrollment_id' => $enrollment->id,
            'status' => 'pending',
            'amount' => 300,
        ]);
    }

    /**
     * الطالب قد يُغلق نافذة Stripe الأولى بلا إكمال الدفع — يجب أن يبقى قادراً
     * على إعادة محاولة الدفع لاحقاً بدل أن يُحرَم منها (كان EnrollmentController
     * يفتقر لمسار مكافئ لـ BookingController::checkout قبل هذا الإصلاح).
     */
    public function test_student_can_retry_checkout_for_a_pending_payment_enrollment(): void
    {
        [$center] = $this->createVerifiedCenter();
        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6));
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $enrollment = app(EnrollmentService::class)->initiateEnrollment($student, $course);

        $checkout = $this->as($studentToken)->postJson("/api/enrollments/{$enrollment->id}/checkout");

        $checkout->assertStatus(200);
        $this->assertNotEmpty($checkout->json('data.checkout_url'));
    }

    public function test_checkout_rejects_an_enrollment_that_is_not_pending_payment(): void
    {
        [$center] = $this->createVerifiedCenter();
        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6));
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;
        $admin = User::factory()->admin()->create();

        $enrollment = app(EnrollmentService::class)->createManualEnrollment($student, $course, $admin, 'تسوية');

        $checkout = $this->as($studentToken)->postJson("/api/enrollments/{$enrollment->id}/checkout");

        $checkout->assertStatus(422);
    }

    public function test_other_students_cannot_checkout_someone_elses_enrollment(): void
    {
        [$center] = $this->createVerifiedCenter();
        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6));
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $owner = $this->createStudent();
        $intruder = $this->createStudent();
        $intruderToken = User::find($intruder->user_id)->createToken('t')->plainTextToken;

        $enrollment = app(EnrollmentService::class)->initiateEnrollment($owner, $course);

        $checkout = $this->as($intruderToken)->postJson("/api/enrollments/{$enrollment->id}/checkout");

        $checkout->assertStatus(403);
    }

    public function test_student_cannot_enroll_twice_in_same_course(): void
    {
        [$center] = $this->createVerifiedCenter();
        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6));
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $student = $this->createStudent();

        app(EnrollmentService::class)->initiateEnrollment($student, $course);

        $this->expectException(ValidationException::class);
        app(EnrollmentService::class)->initiateEnrollment($student, $course);
    }

    public function test_manual_enrollment_is_confirmed_immediately_and_audit_logged(): void
    {
        [$center] = $this->createVerifiedCenter();
        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6));
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $student = $this->createStudent();
        $admin = User::factory()->admin()->create();

        $enrollment = app(EnrollmentService::class)->createManualEnrollment($student, $course, $admin, 'تسوية شكوى');

        $this->assertSame('confirmed', $enrollment->status);
        $this->assertTrue($enrollment->is_manual);
        $this->assertSame(1, $course->fresh()->enrolled_count);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'enrollment.manual_created',
            'user_id' => $admin->id,
        ]);
    }

    public function test_course_fills_to_capacity_and_rejects_further_enrollment(): void
    {
        [$center] = $this->createVerifiedCenter();
        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6), maxSeats: 1);
        $course->schedules()->create(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $student1 = $this->createStudent();
        $student2 = $this->createStudent();

        $enrollment = app(EnrollmentService::class)->initiateEnrollment($student1, $course);
        app(EnrollmentService::class)->confirmEnrollment($enrollment);

        $this->assertSame('full', $course->fresh()->status);

        $this->expectException(ValidationException::class);
        app(EnrollmentService::class)->initiateEnrollment($student2, $course->fresh());
    }

    public function test_center_sees_only_their_own_enrollments_via_index_endpoint(): void
    {
        [$center] = $this->createVerifiedCenter();
        $centerToken = User::find($center->user_id)->createToken('t')->plainTextToken;
        [$otherCenter] = $this->createVerifiedCenter();

        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6));
        $otherCourse = $this->createActiveCourse($otherCenter, teacherPrice: 80, margin: 40, start: $start, end: $start->copy()->addDays(6));
        $student = $this->createStudent();

        $myEnrollment = Enrollment::create([
            'reference' => 'EN-MINE-'.rand(1000, 9999),
            'student_id' => $student->id,
            'course_id' => $course->id,
            'teacher_id' => $center->id,
            'amount_paid' => 150,
            'provider_amount' => 100,
            'platform_amount' => 50,
            'margin_percent_snapshot' => 50,
            'status' => 'confirmed',
        ]);

        $otherEnrollment = Enrollment::create([
            'reference' => 'EN-OTH-'.rand(1000, 9999),
            'student_id' => $student->id,
            'course_id' => $otherCourse->id,
            'teacher_id' => $otherCenter->id,
            'amount_paid' => 112,
            'provider_amount' => 80,
            'platform_amount' => 32,
            'margin_percent_snapshot' => 40,
            'status' => 'confirmed',
        ]);

        $response = $this->as($centerToken)->getJson('/api/enrollments');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($myEnrollment->id));
        $this->assertFalse($ids->contains($otherEnrollment->id));
    }

    public function test_center_can_filter_own_enrollments_by_student_id(): void
    {
        [$center] = $this->createVerifiedCenter();
        $centerToken = User::find($center->user_id)->createToken('t')->plainTextToken;

        $start = Carbon::now()->next(1)->startOfDay();
        $course = $this->createActiveCourse($center, teacherPrice: 100, margin: 50, start: $start, end: $start->copy()->addDays(6));
        $studentA = $this->createStudent();
        $studentB = $this->createStudent();

        $enrollmentA = Enrollment::create([
            'reference' => 'EN-A-'.rand(1000, 9999),
            'student_id' => $studentA->id,
            'course_id' => $course->id,
            'teacher_id' => $center->id,
            'amount_paid' => 150,
            'provider_amount' => 100,
            'platform_amount' => 50,
            'margin_percent_snapshot' => 50,
            'status' => 'confirmed',
        ]);

        Enrollment::create([
            'reference' => 'EN-B-'.rand(1000, 9999),
            'student_id' => $studentB->id,
            'course_id' => $course->id,
            'teacher_id' => $center->id,
            'amount_paid' => 150,
            'provider_amount' => 100,
            'platform_amount' => 50,
            'margin_percent_snapshot' => 50,
            'status' => 'confirmed',
        ]);

        $response = $this->as($centerToken)->getJson("/api/enrollments?student_id={$studentA->id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$enrollmentA->id], $ids->all());
    }

    /**
     * @return array{0: Teacher}
     */
    private function createVerifiedCenter(): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'training_center', 'status' => 'verified']);

        return [$teacher];
    }

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create();

        return Student::create(['user_id' => $user->id, 'education_type' => 'training']);
    }

    private function createActiveCourse(Teacher $center, float $teacherPrice, float $margin, Carbon $start, Carbon $end, int $maxSeats = 20): Course
    {
        $field = CourseField::create(['code' => 'field-'.uniqid(), 'name_ar' => 'مجال']);
        $computed = app(PricingService::class)->calculateStudentPrice($teacherPrice, $margin);

        return Course::create([
            'teacher_id' => $center->id,
            'title' => 'دورة',
            'course_field_id' => $field->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_sessions' => 4,
            'max_seats' => $maxSeats,
            'provider_price' => $teacherPrice,
            'platform_margin_percent' => $margin,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);
    }
}
