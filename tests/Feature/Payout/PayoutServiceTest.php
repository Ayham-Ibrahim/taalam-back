<?php

namespace Tests\Feature\Payout;

use App\Jobs\CompleteEndedSessionsJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseField;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\Payout;
use App\Models\PayoutItem;
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
            'teacher_amount' => $computed['provider_net'],
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
            $this->assertSame('لا توجد مستحقات غير مدفوعة لهذا المعلم ضمن هذه الفترة', $e->errors()['period'][0]);
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

    /**
     * دورة جلستين بمستحق 100 لكل تسجيل: صُرفت الجلسة الأولى بتسجيل واحد (50)، ثم سجّل طالب
     * متأخر — حصته من الجلسة المصروفة (50) تُضاف للدفعة التالية كبند تسوية، مرة واحدة فقط.
     */
    public function test_late_enrollment_adds_a_settlement_for_already_paid_course_sessions(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 2);
        $first = $this->createCompletedCourseSession($course, sequenceNo: 1, scheduledAt: now()->subDays(10));
        $this->enroll($course);

        $service = app(PayoutService::class);
        $this->assertEquals(50.0, (float) $service->generateForPeriod($center, now()->subDays(11), now()->subDays(9))->gross_amount);

        $this->enroll($course);
        $second = $this->createCompletedCourseSession($course, sequenceNo: 2, scheduledAt: now()->subHours(2));

        $payout = $service->generateForPeriod($center, now()->subDay(), now()->addDay());

        // الجلسة الثانية بتسجيلين (100) + تسوية الأولى (100 − 50 المصروفة)
        $this->assertEquals(150.0, (float) $payout->gross_amount);
        $this->assertSame(1, $payout->sessions_count);
        $this->assertDatabaseHas('payout_items', ['payout_id' => $payout->id, 'class_session_id' => $second->id, 'type' => 'session', 'amount' => 100]);
        $this->assertDatabaseHas('payout_items', ['payout_id' => $payout->id, 'class_session_id' => $first->id, 'type' => 'settlement', 'amount' => 50]);

        $this->expectException(ValidationException::class);
        $service->generateForPeriod($center, now()->subDays(30), now()->addDay());
    }

    /** لا جلسات جديدة في الفترة لكن توجد تسوية مستحقة — تُولَّد الدفعة بها وحدها */
    public function test_a_payout_can_hold_only_settlements(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 2);
        $session = $this->createCompletedCourseSession($course, sequenceNo: 1, scheduledAt: now()->subDays(10));
        $this->enroll($course);

        $service = app(PayoutService::class);
        $service->generateForPeriod($center, now()->subDays(11), now()->subDays(9));
        $this->enroll($course);

        $payout = $service->generateForPeriod($center, now()->subDay(), now()->addDay());

        $this->assertEquals(50.0, (float) $payout->gross_amount);
        $this->assertSame(0, $payout->sessions_count);
        $this->assertDatabaseHas('payout_items', ['payout_id' => $payout->id, 'class_session_id' => $session->id, 'type' => 'settlement']);
    }

    /** التسوية تتبع نسبة الحضور المثبَّتة للجلسة — غياب المركز (0%) لا يولّد تسوية */
    public function test_settlement_follows_the_sessions_payout_percent(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 2);
        $this->createCompletedCourseSession($course, sequenceNo: 1, scheduledAt: now()->subDays(10), payoutPercent: 50);
        $this->createCompletedCourseSession($course, sequenceNo: 2, scheduledAt: now()->subDays(10), payoutPercent: 0);
        $this->enroll($course);

        $service = app(PayoutService::class);
        $this->assertEquals(25.0, (float) $service->generateForPeriod($center, now()->subDays(11), now()->subDays(9))->gross_amount);
        $this->enroll($course);

        // الجلسة الأولى 50%: مستحقها الآن 50 والمصروف 25 → تسوية 25. الثانية 0% → لا شيء
        $this->assertEquals(25.0, (float) $service->generateForPeriod($center, now()->subDay(), now()->addDay())->gross_amount);
    }

    /**
     * سقف الدورة: total_sessions جلسة عقدها المركز فعلاً. دورة جلستين بتسجيل واحد (50 للجلسة):
     * غاب عن الأولى، عقد الثانية والثالثة (زائدة/تعويضية) → يُصرف عن الثانية والثالثة.
     */
    public function test_a_session_after_an_absence_fills_the_absent_sessions_slot(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 2);
        $absent = $this->createCompletedCourseSession($course, 1, now()->subHours(6), 0, ClassSession::OUTCOME_TEACHER_ABSENT);
        $this->createCompletedCourseSession($course, 2, now()->subHours(4), 100, ClassSession::OUTCOME_ATTENDED);
        $this->createCompletedCourseSession($course, 3, now()->subHours(2), 100, ClassSession::OUTCOME_ATTENDED);
        $this->enroll($course);

        $payout = app(PayoutService::class)->generateForPeriod($center, now()->subDay(), now()->addDay());

        $this->assertEquals(100.0, (float) $payout->gross_amount);
        $this->assertSame(2, $payout->sessions_count);
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $absent->id]);
    }

    /** دورة قديمة وُلِّدت لها جلسات أكثر من العدد المعلن — لا يُصرف أكثر من total_sessions جلسة */
    public function test_attended_sessions_beyond_total_sessions_are_not_paid(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 2);
        $this->createCompletedCourseSession($course, 1, now()->subHours(6), 100, ClassSession::OUTCOME_ATTENDED);
        $this->createCompletedCourseSession($course, 2, now()->subHours(4), null, ClassSession::OUTCOME_UNTRACKED);
        $extra = $this->createCompletedCourseSession($course, 3, now()->subHours(2), 100, ClassSession::OUTCOME_ATTENDED);
        $this->enroll($course);

        $payout = app(PayoutService::class)->generateForPeriod($center, now()->subDay(), now()->addDay());

        $this->assertEquals(100.0, (float) $payout->gross_amount);
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $extra->id]);
    }

    /** السقف على عمر الدورة: ما استُهلك في دفعة سابقة يُخصم من المتاح في التالية */
    public function test_the_course_cap_counts_slots_used_in_previous_payouts(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 2);
        $this->createCompletedCourseSession($course, 1, now()->subDays(10), 100, ClassSession::OUTCOME_ATTENDED);
        $this->enroll($course);

        $service = app(PayoutService::class);
        $service->generateForPeriod($center, now()->subDays(11), now()->subDays(9));

        $second = $this->createCompletedCourseSession($course, 2, now()->subHours(4), 100, ClassSession::OUTCOME_ATTENDED);
        $extra = $this->createCompletedCourseSession($course, 3, now()->subHours(2), 100, ClassSession::OUTCOME_ATTENDED);

        $payout = $service->generateForPeriod($center, now()->subDay(), now()->addDay());

        $this->assertEquals(50.0, (float) $payout->gross_amount);
        $this->assertDatabaseHas('payout_items', ['class_session_id' => $second->id]);
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $extra->id]);
    }

    /** غياب الطلاب (student_no_show) يُصرف بنسبته خارج السقف ولا يستهلك مكاناً */
    public function test_a_student_no_show_session_is_paid_outside_the_cap(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 2);
        $this->createCompletedCourseSession($course, 1, now()->subHours(6), 100, ClassSession::OUTCOME_ATTENDED);
        $this->createCompletedCourseSession($course, 2, now()->subHours(4), 50, ClassSession::OUTCOME_STUDENT_NO_SHOW);
        $this->createCompletedCourseSession($course, 3, now()->subHours(2), 100, ClassSession::OUTCOME_ATTENDED);
        $this->enroll($course);

        $payout = app(PayoutService::class)->generateForPeriod($center, now()->subDay(), now()->addDay());

        // 50 + 25 (نصف الثانية) + 50 (الثالثة تملأ المكان الثاني)
        $this->assertEquals(125.0, (float) $payout->gross_amount);
        $this->assertSame(3, $payout->sessions_count);
    }

    /** جلسات زائدة صُرفت قبل تطبيق السقف لا تُضخَّم بتسوية تسجيل متأخر */
    public function test_settlement_skips_sessions_paid_beyond_the_cap_before_it_existed(): void
    {
        [$center] = $this->createVerifiedTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 2);
        $this->enroll($course);

        // صرف قديم لثلاث جلسات (50 لكل منها) من دورة معلنة بجلستين
        $legacy = Payout::create([
            'teacher_id' => $center->id,
            'period_start' => now()->subDays(20)->toDateString(),
            'period_end' => now()->subDays(10)->toDateString(),
            'gross_amount' => 150,
            'net_amount' => 150,
            'sessions_count' => 3,
            'status' => 'paid',
        ]);

        $sessions = collect([1, 2, 3])->map(function (int $n) use ($course, $legacy) {
            $session = $this->createCompletedCourseSession($course, $n, now()->subDays(20 - $n));
            PayoutItem::create(['payout_id' => $legacy->id, 'class_session_id' => $session->id, 'amount' => 50]);

            return $session;
        });

        $this->enroll($course);

        $payout = app(PayoutService::class)->generateForPeriod($center, now()->subDay(), now()->addDay());

        // تسوية الجلستين الأوليين فقط: 100 مستحقة − 50 مصروفة لكل منهما
        $this->assertEquals(100.0, (float) $payout->gross_amount);
        $this->assertDatabaseMissing('payout_items', ['payout_id' => $payout->id, 'class_session_id' => $sessions[2]->id]);
    }

    /**
     * باقة جماعية بجلستين ومستحق 100 لكل حجز (50 للجلسة): صُرفت الأولى بحجز واحد (50)، ثم
     * انضم طالب متأخر وسُجِّل فيها — حصته منها (50) تُضاف للدفعة التالية كتسوية، مرة واحدة.
     */
    public function test_late_group_joiner_adds_a_settlement_for_already_paid_sessions(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $package = $this->createGroupPackage($teacher);
        $owner = $this->joinGroup($package);
        $first = $this->createCompletedGroupSession($owner, now()->subDays(10));

        $service = app(PayoutService::class);
        $this->assertEquals(50.0, (float) $service->generateForPeriod($teacher, now()->subDays(11), now()->subDays(9))->gross_amount);

        $this->joinGroup($package, sessions: [$first]);

        $payout = $service->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        $this->assertEquals(50.0, (float) $payout->gross_amount);
        $this->assertSame(0, $payout->sessions_count);
        $this->assertDatabaseHas('payout_items', ['payout_id' => $payout->id, 'class_session_id' => $first->id, 'type' => 'settlement', 'amount' => 50]);

        $this->expectException(ValidationException::class);
        $service->generateForPeriod($teacher, now()->subDays(30), now()->addDay());
    }

    /** حجز جماعي كان بانتظار الدفع وقت صرف الجلسة ثم دُفع — حصته تُسوّى لاحقاً */
    public function test_a_group_booking_paid_after_the_session_was_paid_out_is_settled(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $package = $this->createGroupPackage($teacher);
        $owner = $this->joinGroup($package);
        $session = $this->createCompletedGroupSession($owner, now()->subDays(10));
        $pending = $this->joinGroup($package, sessions: [$session], status: 'pending_payment');

        $service = app(PayoutService::class);
        $this->assertEquals(50.0, (float) $service->generateForPeriod($teacher, now()->subDays(11), now()->subDays(9))->gross_amount);

        $pending->update(['status' => 'confirmed']);

        $this->assertEquals(50.0, (float) $service->generateForPeriod($teacher, now()->subDay(), now()->addDay())->gross_amount);
    }

    /** التسوية تتبع نسبة حضور الجلسة المثبَّتة — جلسة غاب عنها المعلم (0%) لا تُسوّى */
    public function test_group_settlement_follows_the_sessions_payout_percent(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $package = $this->createGroupPackage($teacher);
        $owner = $this->joinGroup($package);
        $halfPaid = $this->createCompletedGroupSession($owner, now()->subDays(10), payoutPercent: 50);
        $unpaid = $this->createCompletedGroupSession($owner, now()->subDays(10), payoutPercent: 0);

        $service = app(PayoutService::class);
        $this->assertEquals(25.0, (float) $service->generateForPeriod($teacher, now()->subDays(11), now()->subDays(9))->gross_amount);

        $this->joinGroup($package, sessions: [$halfPaid, $unpaid]);

        // 50%: مستحقها الآن 50 والمصروف 25 → تسوية 25. الثانية 0% → لا شيء
        $this->assertEquals(25.0, (float) $service->generateForPeriod($teacher, now()->subDay(), now()->addDay())->gross_amount);
    }

    /** الأصلية التي لها تعويضية: حصة المنضم بعد موعدها تُصرف عن التعويضية فقط — لا تسوية على الأصلية */
    public function test_no_group_settlement_on_an_original_whose_share_moves_to_its_makeup(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $package = $this->createGroupPackage($teacher);

        // سطر حضور المالك قبل موعد الأصلية — المنضم بعده وحده يُستبعد منها
        $this->travelTo(now()->subDays(11));
        $owner = $this->joinGroup($package);
        $original = $this->createCompletedGroupSession($owner, now()->addDay(), payoutPercent: 50, outcome: ClassSession::OUTCOME_STUDENT_NO_SHOW);
        $this->travelBack();

        $makeup = $this->createCompletedGroupSession($owner, now()->subHours(2));
        $makeup->update(['is_makeup' => true, 'makeup_for_session_id' => $original->id]);

        $service = app(PayoutService::class);
        $this->assertEquals(25.0, (float) $service->generateForPeriod($teacher, now()->subDays(11), now()->subDays(9))->gross_amount);

        $this->joinGroup($package, sessions: [$original, $makeup]);

        // التعويضية بالحجزين (100)، ولا تسوية على الأصلية
        $payout = $service->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        $this->assertEquals(100.0, (float) $payout->gross_amount);
        $this->assertDatabaseMissing('payout_items', ['payout_id' => $payout->id, 'class_session_id' => $original->id]);
    }

    /** سقف الحجز الفردي: باقة جلسة واحدة (100) بجلستين منعقدتين — تُصرف الأولى زمنياً فقط */
    public function test_individual_booking_sessions_beyond_sessions_total_are_not_paid(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        [$booking, $first] = $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 1);
        $extra = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 2,
            'scheduled_at' => now()->subHour(),
            'duration_min' => 60,
            'status' => 'completed',
        ]);
        SessionAttendee::create(['class_session_id' => $extra->id, 'student_id' => $booking->student_id, 'booking_id' => $booking->id, 'attendance' => 'present']);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        $this->assertEquals(100.0, (float) $payout->gross_amount);
        $this->assertDatabaseHas('payout_items', ['payout_id' => $payout->id, 'class_session_id' => $first->id]);
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $extra->id]);
    }

    /**
     * حجز بجلستين (50 للجلسة): غاب المعلم عن الأولى فلا تستهلك مكاناً، عُقدت الثانية والثالثة
     * (تعويضية) فتُصرفان، والرابعة المنعقدة خارج السقف. غياب الطلاب يُصرف بنسبته خارج السقف.
     */
    public function test_booking_cap_skips_teacher_absences_and_pays_student_no_shows_outside_it(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $owner = $this->joinGroup($this->createGroupPackage($teacher));
        $this->createCompletedGroupSession($owner, now()->subHours(10), 0, ClassSession::OUTCOME_TEACHER_ABSENT);
        $second = $this->createCompletedGroupSession($owner, now()->subHours(8), 100, ClassSession::OUTCOME_ATTENDED);
        $noShow = $this->createCompletedGroupSession($owner, now()->subHours(6), 50, ClassSession::OUTCOME_STUDENT_NO_SHOW);
        $third = $this->createCompletedGroupSession($owner, now()->subHours(4), 100, ClassSession::OUTCOME_ATTENDED);
        $beyond = $this->createCompletedGroupSession($owner, now()->subHours(2), 100, ClassSession::OUTCOME_ATTENDED);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        // 50 + 50 منعقدتان ضمن السقف + 25 غياب الطلاب خارجه
        $this->assertEquals(125.0, (float) $payout->gross_amount);
        $this->assertSame(3, $payout->sessions_count);
        foreach ([$second, $noShow, $third] as $paid) {
            $this->assertDatabaseHas('payout_items', ['class_session_id' => $paid->id]);
        }
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $beyond->id]);
    }

    /** السقف على عمر الحجز: ما صُرف في دفعة سابقة يُخصم من المتاح في التالية */
    public function test_booking_cap_counts_sessions_paid_in_previous_payouts(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $owner = $this->joinGroup($this->createGroupPackage($teacher));
        $this->createCompletedGroupSession($owner, now()->subDays(10));
        $this->createCompletedGroupSession($owner, now()->subDays(10));
        $extra = $this->createCompletedGroupSession($owner, now()->subHours(2));

        $service = app(PayoutService::class);
        $this->assertEquals(100.0, (float) $service->generateForPeriod($teacher, now()->subDays(11), now()->subDays(9))->gross_amount);

        // الجلسة الزائدة وحدها بلا مستحق — لا كشف فارغ
        $this->expectException(ValidationException::class);
        try {
            $service->generateForPeriod($teacher, now()->subDay(), now()->addDay());
        } finally {
            $this->assertDatabaseMissing('payout_items', ['class_session_id' => $extra->id]);
            $this->assertDatabaseCount('payouts', 1);
        }
    }

    /** المجموعة: السقف على حصة كل حجز لا على الجلسة — من استنفد سقفه تسقط حصته وحدها */
    public function test_group_session_drops_only_the_share_of_a_booking_beyond_its_cap(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $package = $this->createGroupPackage($teacher);
        $short = $this->joinGroup($package);
        $short->update(['sessions_total' => 1, 'sessions_remaining' => 1]);
        $first = $this->createCompletedGroupSession($short, now()->subHours(4));
        $second = $this->createCompletedGroupSession($short, now()->subHours(2));
        $this->joinGroup($package, sessions: [$first, $second]);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());

        // الأول بجلسة واحدة (حصته 100). الأولى: 100 + 50، الثانية: حصة الثاني وحدها (50) — الأول استنفد جلسته
        $this->assertDatabaseHas('payout_items', ['class_session_id' => $first->id, 'amount' => 150]);
        $this->assertDatabaseHas('payout_items', ['class_session_id' => $second->id, 'amount' => 50]);
        $this->assertEquals(200.0, (float) $payout->gross_amount);
    }

    /** الباقة الفردية لا تُسوّى: حجز واحد ثابت — حتى سطر حضور مضاف لاحقاً لا يرفع ما صُرف */
    public function test_individual_package_sessions_are_not_settled(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        [$booking, $session] = $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 1);

        $service = app(PayoutService::class);
        $this->assertEquals(100.0, (float) $service->generateForPeriod($teacher, now()->subDay(), now()->addDay())->gross_amount);

        $this->joinGroup($booking->package, sessions: [$session]);

        $this->expectException(ValidationException::class);
        $service->generateForPeriod($teacher, now()->subDay(), now()->addDay());
    }

    /** حالتان فقط: غير مدفوع عند التوليد، ثم مدفوع مباشرة — بلا اعتماد، ومرة واحدة فقط */
    public function test_a_generated_payout_is_unpaid_and_is_marked_paid_directly_once(): void
    {
        [$teacher] = $this->createVerifiedTeacher('school');
        $this->createCompletedPackageSession($teacher, teacherPrice: 100, sessionsTotal: 4);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDay(), now()->addDay());
        $this->assertSame('pending', $payout->status);

        $paid = app(PayoutService::class)->markPaid($payout, 'REF-123');
        $this->assertSame('paid', $paid->status);
        $this->assertSame('REF-123', $paid->transfer_reference);
        $this->assertNotNull($paid->paid_at);

        $this->expectException(ValidationException::class);
        app(PayoutService::class)->markPaid($paid, 'REF-456');
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
            'teacher_amount' => $computed['provider_net'],
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

    private function createGroupPackage(Teacher $teacher): Package
    {
        $subject = Subject::create(['code' => 'pg-'.uniqid(), 'name_ar' => 'مادة']);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة جماعية',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 10,
            'sessions_count' => 2,
            'teacher_price' => 50,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);
    }

    /**
     * حجز على الباقة بمستحق معلم 100 لجلستين (50 للجلسة)، مسجَّل في $sessions.
     *
     * @param  array<int, ClassSession>  $sessions
     */
    private function joinGroup(Package $package, array $sessions = [], string $status = 'confirmed'): Booking
    {
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $package->teacher_id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 2,
            'sessions_remaining' => 2,
            'status' => $status,
        ]);

        foreach ($sessions as $session) {
            $this->registerAttendee($session, $booking);
        }

        return $booking;
    }

    /** جلسة مجموعة مملوكة لحجز أول منضم ($owner) ومسجَّل فيها */
    private function createCompletedGroupSession(Booking $owner, $scheduledAt, ?float $payoutPercent = null, ?string $outcome = null): ClassSession
    {
        $session = $owner->sessions()->create([
            'teacher_id' => $owner->teacher_id,
            'sequence_no' => $owner->sessions()->count() + 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'completed',
            'attendance_outcome' => $outcome,
            'teacher_payout_percent' => $payoutPercent,
        ]);

        $this->registerAttendee($session, $owner);

        return $session;
    }

    private function registerAttendee(ClassSession $session, Booking $booking): void
    {
        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);
    }

    private function createCourse(Teacher $center, int $totalSessions): Course
    {
        $field = CourseField::create(['code' => 'pf-'.uniqid(), 'name_ar' => 'مجال']);

        return Course::create([
            'teacher_id' => $center->id,
            'title' => 'دورة',
            'course_field_id' => $field->id,
            'start_date' => now()->subDays(15)->toDateString(),
            'end_date' => now()->addDays(15)->toDateString(),
            'total_sessions' => $totalSessions,
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
    }

    private function createCompletedCourseSession(Course $course, int $sequenceNo, $scheduledAt, ?float $payoutPercent = null, ?string $outcome = null): ClassSession
    {
        return ClassSession::create([
            'attendance_outcome' => $outcome,
            'course_id' => $course->id,
            'teacher_id' => $course->teacher_id,
            'sequence_no' => $sequenceNo,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'completed',
            'teacher_payout_percent' => $payoutPercent,
        ]);
    }

    /** تسجيل مدفوع بمستحق مركز 100 */
    private function enroll(Course $course): Enrollment
    {
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'training']);

        return Enrollment::create([
            'reference' => 'EN-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'course_id' => $course->id,
            'teacher_id' => $course->teacher_id,
            'amount_paid' => 150,
            'provider_amount' => 100,
            'platform_amount' => 50,
            'margin_percent_snapshot' => 50,
            'status' => 'confirmed',
        ]);
    }
}
