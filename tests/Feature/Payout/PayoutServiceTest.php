<?php

namespace Tests\Feature\Payout;

use App\Jobs\CompleteEndedSessionsJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseField;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PayoutService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payout_aggregates_individual_package_sessions_correctly(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        [$booking, $session] = $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        // teacher_price سعر الساعة/الجلسة الواحدة مباشرة — teacher_amount الكلي = 100×4 ÷ 4 جلسات = 100 لكل جلسة
        $this->assertEquals(100.0, (float) $payout->gross_amount);
        $this->assertSame(1, $payout->sessions_count);
        $this->assertDatabaseHas('payout_items', ['payout_id' => $payout->id, 'class_session_id' => $session->id, 'amount' => 100]);
    }

    /**
     * لا شيء يُحوّل الجلسة إلى status='completed' بمجرد انتهاء وقتها إلا
     * CompleteEndedSessionsJob (مجدولة كل دقيقة) — بلا تشغيلها تبقى الجلسة
     * 'scheduled' للأبد فيرفض التوليد دائماً "لا توجد جلسات مكتملة" رغم
     * انتهاء الجلسة فعلياً. هذا يثبت السيناريو الحقيقي المُبلَّغ عنه: جلسة
     * حديثة الإنشاء (لا تحمل status='completed' يدوياً كما في باقي اختبارات
     * هذا الملف) ينتهي وقتها ثم تصبح قابلة للتوليد فقط بعد تشغيل الـ job.
     */
    public function test_payout_generation_works_after_the_scheduled_completion_job_marks_the_session_completed(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $subject = Subject::create(['code' => 'po-'.uniqid(), 'name_ar' => 'مادة']);
        $computed = app(PricingService::class)->calculateStudentPrice(100, 60, 1);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
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
            'amount_paid' => $computed['student_price'],
            'teacher_amount' => $computed['provider_total'],
            'platform_amount' => $computed['platform_revenue'],
            'margin_percent_snapshot' => 60,
            'sessions_total' => 1,
            'sessions_remaining' => 1,
            'status' => 'confirmed',
        ]);

        // status='scheduled' الافتراضي — لا تعيين يدوي لـ 'completed' هنا عمداً
        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->subHours(2),
            'duration_min' => 60,
        ]);

        $this->assertSame('scheduled', $session->fresh()->status);

        $blocked = false;
        try {
            app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());
        } catch (ValidationException $e) {
            $blocked = true;
            $this->assertSame('لا توجد جلسات مكتملة غير مدفوعة لهذا المعلم ضمن هذه الفترة', $e->errors()['period'][0]);
        }
        $this->assertTrue($blocked, 'Expected generation to be blocked before the completion job runs');

        (new CompleteEndedSessionsJob)->handle(app(\App\Services\SessionService::class));
        $this->assertSame('completed', $session->fresh()->status);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        $this->assertEquals(100.0, (float) $payout->gross_amount);
    }

    public function test_payout_excludes_sessions_already_paid_in_a_previous_payout(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $service = app(PayoutService::class);
        $service->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        $this->expectException(ValidationException::class);
        $service->generateForPeriod($teacher, now()->subDay(), now()->addDay());
    }

    public function test_payout_aggregates_course_sessions_from_all_active_enrollments(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $field = CourseField::create(['code' => 'pf-'.uniqid(), 'name_ar' => 'مجال']);

        $course = Course::create([
            'teacher_id' => $center->id,
            'title' => 'دورة',
            'course_field_id' => $field->id,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'total_sessions' => 2,
            'max_seats' => 10,
            'provider_price' => 100,
            'platform_margin_percent' => 50,
            'student_price' => 150,
            'platform_revenue' => 50,
            'status' => 'active',
            'approved_at' => now(),
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);

        $session = ClassSession::create([
            'course_id' => $course->id,
            'teacher_id' => $center->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->subHours(2),
            'duration_min' => 60,
            'status' => 'completed',
        ]);

        // طالبان مسجَّلان بنفس السعر: 100 لكل منهما → 200 إجمالي عائد المركز ÷ جلستين = 100 لكل جلسة
        foreach ([1, 2] as $i) {
            $studentUser = User::factory()->student()->create();
            $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'training']);

            Enrollment::create([
                'reference' => 'EN-'.strtoupper(uniqid())."$i",
                'student_id' => $student->id,
                'course_id' => $course->id,
                'teacher_id' => $center->id,
                'amount_paid' => 150,
                'provider_amount' => 100,
                'platform_amount' => 50,
                'margin_percent_snapshot' => 50,
                'status' => 'confirmed',
            ]);
        }

        $payout = app(PayoutService::class)->generateForPeriod($center, now()->subDays(5), now()->addDays(5));

        $this->assertEquals(100.0, (float) $payout->gross_amount);
    }

    public function test_approve_and_mark_paid_flow_enforces_order(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);
        $admin = User::factory()->admin()->create();

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        $this->expectException(ValidationException::class);
        app(PayoutService::class)->markPaid($payout, 'REF-123');
    }

    public function test_full_approve_then_paid_flow_succeeds_in_order(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);
        $admin = User::factory()->admin()->create();

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());
        $approved = app(PayoutService::class)->approve($payout, $admin);
        $this->assertSame('approved', $approved->status);

        $paid = app(PayoutService::class)->markPaid($approved, 'REF-123');
        $this->assertSame('paid', $paid->status);
        $this->assertSame('REF-123', $paid->transfer_reference);
    }

    /**
     * التوليد يجب ألا يولّد استعلاماً منفصلاً لكل جلسة (كان النمط القديم يفعل هذا
     * تماماً) — عدد الاستعلامات يجب أن يبقى شبه ثابت بصرف النظر عن عدد الجلسات.
     */
    public function test_generating_a_payout_does_not_scale_queries_with_session_count(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');

        for ($i = 0; $i < 8; $i++) {
            $this->createCompletedPackageSession($teacher, teacherPrice: 50, sessionsTotal: 1);
        }

        DB::enableQueryLog();
        app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // ثابت بغض النظر عن العدد؛ النمط القديم كان يولّد ~2 استعلام إضافي *لكل جلسة*
        // (16+ لثماني جلسات) — أي رقم أقل من ذلك بكثير يثبت عدم التوسّع الخطي.
        $this->assertLessThan(20, $queryCount, "Expected a bounded query count, got {$queryCount} for 8 sessions");
    }

    /**
     * @return array{0: Teacher}
     */
    private function createVerifiedTeacher(string $type): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => $type, 'status' => 'verified']);

        return [$teacher];
    }

    /**
     * @return array{0: Booking, 1: ClassSession}
     */
    private function createCompletedPackageSession(Teacher $teacher, float $teacherPrice, int $sessionsTotal): array
    {
        $subject = Subject::create(['code' => 'po-'.uniqid(), 'name_ar' => 'مادة']);

        $computed = app(PricingService::class)->calculateStudentPrice($teacherPrice, 60, $sessionsTotal);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => $sessionsTotal,
            'teacher_price' => $teacherPrice,
            'platform_margin_percent' => 60,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
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
            'amount_paid' => $computed['student_price'],
            'teacher_amount' => $computed['provider_total'],
            'platform_amount' => $computed['platform_revenue'],
            'margin_percent_snapshot' => 60,
            'sessions_total' => $sessionsTotal,
            'sessions_remaining' => $sessionsTotal,
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->subHours(2),
            'duration_min' => 60,
            'status' => 'completed',
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'present',
        ]);

        return [$booking, $session];
    }
}
