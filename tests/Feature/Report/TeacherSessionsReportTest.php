<?php

namespace Tests\Feature\Report;

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
use App\Services\TeacherSessionsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherSessionsReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * حجز فردي 4 جلسات: المعلم 400 (100/جلسة) والمنصة 240 (60/جلسة). الحضور التلقائي فقط
     * (attendance يبقى registered) — كان التقرير سابقاً يعرض صفراً لأنه يشترط present يدوياً.
     */
    public function test_report_matches_payout_amounts_from_automatic_attendance(): void
    {
        [$teacher, $booking] = $this->createIndividualBooking();

        $this->completedSession($booking, 1, now()->subDays(4), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 2, now()->subDays(3), ClassSession::OUTCOME_STUDENT_NO_SHOW, 50);
        $this->completedSession($booking, 3, now()->subDays(2), ClassSession::OUTCOME_TEACHER_ABSENT, 0);
        $this->completedSession($booking, 4, now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100);

        // الأولى مصروفة فعلاً في كشف، والبقية مكتملة غير مصروفة — الاثنان بنفس الحساب
        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(5), now()->subDays(4));
        $this->assertEquals(100.0, (float) $payout->gross_amount);

        $report = app(TeacherSessionsReportService::class)->getReport(now()->subDays(10), now(), null);

        // غياب المعلم لا يُعد حصة؛ غياب الطلاب يُعد بنسبته (50). بلا تعويضيات تبقى قيمة الجلستين
        // الفائتتين للمنصة: 120 + (50 + 60) + (100 + 60) = 390 — والمجموع 640 ما دفعه الطالب
        $this->assertSame(3, $report['totals']['sessionsCount']);
        $this->assertEquals(250.0, $report['totals']['teacherRevenue']);
        $this->assertEquals(390.0, $report['totals']['platformRevenue']);
        $this->assertSame($teacher->id, $report['rows'][0]['teacherId']);
    }

    public function test_sessions_beyond_the_booking_cap_are_not_counted(): void
    {
        [, $booking] = $this->createIndividualBooking(sessionsTotal: 2);

        $this->completedSession($booking, 1, now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 2, now()->subDays(2), ClassSession::OUTCOME_ATTENDED, 100);
        // جلسة زائدة عن sessions_total (بيانات قديمة) — لا مستحق ولا عائد
        $this->completedSession($booking, 3, now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100);

        $report = app(TeacherSessionsReportService::class)->getReport(null, null, null);

        $this->assertSame(2, $report['totals']['sessionsCount']);
        $this->assertEquals(200.0, $report['totals']['teacherRevenue']);
        $this->assertEquals(120.0, $report['totals']['platformRevenue']);
    }

    /**
     * دورة 4 جلسات: تسجيل أول (100 للمركز) → 25/جلسة، صُرفت جلستان (50)، ثم تسجيل متأخر
     * يرفع الجلسة إلى 50 فتستحق الجلستان المصروفتان تسوية 25 لكلٍّ — قبل توليدها في كشف.
     * ما يعرضه التقرير "خارج الكشوف" يساوي بالضبط ما يُولَّد في الكشف التالي.
     */
    public function test_report_includes_pending_late_enrollment_settlements_and_matches_the_next_payout(): void
    {
        $center = $this->createTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 4);
        $this->enroll($course);

        $this->completedCourseSession($course, 1, now()->subDays(5));
        $this->completedCourseSession($course, 2, now()->subDays(4));
        $first = app(PayoutService::class)->generateForPeriod($center, now()->subDays(6), now()->subDays(4));
        $this->assertEquals(50.0, (float) $first->gross_amount);

        $this->enroll($course);
        $this->completedCourseSession($course, 3, now()->subDays(2));

        $before = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertSame(3, $before['sessionsCount']);
        $this->assertSame(1, $before['unpaidCount']);
        $this->assertEquals(150.0, $before['teacherRevenue']);
        $this->assertEquals(50.0, $before['inPayoutsAmount']);
        $this->assertEquals(100.0, $before['notInPayoutsAmount']);
        $this->assertEquals(50.0, $before['settlementsAmount']);
        $this->assertEquals(75.0, $before['platformRevenue']);

        $next = app(PayoutService::class)->generateForPeriod($center, now()->subDays(3), now());
        $this->assertEquals($before['notInPayoutsAmount'], (float) $next->gross_amount);

        $after = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(150.0, $after['teacherRevenue']);
        $this->assertEquals(150.0, $after['inPayoutsAmount']);
        $this->assertEquals(0.0, $after['notInPayoutsAmount']);
        $this->assertEquals(50.0, $after['settlementsAmount']);
        $this->assertSame(0, $after['unpaidCount']);
    }

    /**
     * حجز فردي 4 جلسات (640 = 400 للمعلم + 240 للمنصة): لم يحضر الطالب الثانية (50 للمعلم)،
     * ثم عُقدت تعويضيتها فملأت مكانها كاملة — المعلم 450، أي 50 فوق قيمة عقده من هامش المنصة.
     * عائد المنصة 240 − 50 = 190 = ما دفعه الطالب − ما صُرف للمعلم، قبل توليد الكشف وبعده.
     * الخصم يُنسب للتعويضية (حيث تجاوز المصروف قيمة العقد) لا للجلسات قبلها.
     */
    public function test_platform_revenue_bears_the_student_no_show_compensation_once_its_slot_is_refilled(): void
    {
        [$teacher, $booking] = $this->createIndividualBooking();

        $this->completedSession($booking, 1, now()->subDays(6), ClassSession::OUTCOME_ATTENDED, 100);
        $noShow = $this->completedSession($booking, 2, now()->subDays(5), ClassSession::OUTCOME_STUDENT_NO_SHOW, 50);
        $this->completedSession($booking, 3, now()->subDays(4), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 4, now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 2, now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100, makeupFor: $noShow);

        $before = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(450.0, $before['teacherRevenue']);
        $this->assertEquals(190.0, $before['platformRevenue']);

        $earlier = app(TeacherSessionsReportService::class)->getReport(now()->subDays(7), now()->subDays(2), null)['totals'];
        $this->assertEquals(180.0, $earlier['platformRevenue']);

        app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(7), now());

        $after = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(450.0, $after['inPayoutsAmount']);
        $this->assertEquals(190.0, $after['platformRevenue']);
    }

    /**
     * بلا تعويضية: ما لم يُصرف للمعلم من الجلسة الفائتة (50 من 100) وحصة المنصة منها (60) يبقيان
     * للمنصة — 180 + 110 = 290، والمعلم 350: المجموع 640 ما دفعه الطالب.
     */
    public function test_student_no_show_without_a_makeup_leaves_the_unpaid_session_value_to_the_platform(): void
    {
        [, $booking] = $this->createIndividualBooking();

        $this->completedSession($booking, 1, now()->subDays(4), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 2, now()->subDays(3), ClassSession::OUTCOME_STUDENT_NO_SHOW, 50);
        $this->completedSession($booking, 3, now()->subDays(2), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 4, now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100);

        $report = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(350.0, $report['teacherRevenue']);
        $this->assertEquals(290.0, $report['platformRevenue']);
    }

    /**
     * التعويض الثابت عن غياب الطلاب (30) يُدفع من قيمة الجلسة الفائتة التي بقيت للمنصة لا من حصتها:
     * حجز 640 (400 + 240) عُقدت ثلاث من جلساته ولم يحضر الطالب الرابعة ولا تعويضية لها — المعلم
     * 300 + 30، والمنصة 240 + 100 − 30 = 310. قبل توليد الكشف وبعده.
     */
    public function test_fixed_student_no_show_compensation_is_paid_from_the_missed_session_value(): void
    {
        [$teacher, $booking] = $this->createIndividualBooking();

        $this->completedSession($booking, 1, now()->subDays(6), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 2, now()->subDays(5), ClassSession::OUTCOME_STUDENT_NO_SHOW, 0, compensation: 30);
        $this->completedSession($booking, 3, now()->subDays(4), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 4, now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);

        $before = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertSame(1, $before['studentNoShowCount']);
        $this->assertEquals(330.0, $before['teacherRevenue']);
        $this->assertEquals(310.0, $before['platformRevenue']);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(7), now());
        $this->assertEquals(330.0, (float) $payout->gross_amount);

        $after = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(330.0, $after['inPayoutsAmount']);
        $this->assertEquals(310.0, $after['platformRevenue']);
    }

    /**
     * لغياب الطلاب تعويضية: الطالب يأخذ الجلسة كاملة فيها والمعلم يُصرف له عنها — لا تعويض ثابت
     * عن الأصلية. المعلم 400 والمنصة 240 = ما دفعه الطالب.
     */
    public function test_no_fixed_compensation_when_the_student_no_show_has_a_makeup(): void
    {
        [$teacher, $booking] = $this->createIndividualBooking();

        $this->completedSession($booking, 1, now()->subDays(6), ClassSession::OUTCOME_ATTENDED, 100);
        $noShow = $this->completedSession($booking, 2, now()->subDays(5), ClassSession::OUTCOME_STUDENT_NO_SHOW, 0, compensation: 30);
        $this->completedSession($booking, 3, now()->subDays(4), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 4, now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 2, now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100, makeupFor: $noShow);

        $before = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(400.0, $before['teacherRevenue']);
        $this->assertEquals(240.0, $before['platformRevenue']);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(7), now());
        $this->assertEquals(400.0, (float) $payout->gross_amount);
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $noShow->id]);

        $after = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];
        $this->assertEquals(400.0, $after['inPayoutsAmount']);
        $this->assertEquals(240.0, $after['platformRevenue']);
    }

    /**
     * التعويض صُرف في كشف قبل إنشاء التعويضية — لا يُسترد، فيُخصم من مستحق التعويضية (100 − 30).
     * النتيجة كما لو أُنشئت التعويضية أولاً: المعلم 400 والمنصة 240.
     */
    public function test_a_compensation_paid_before_the_makeup_is_deducted_from_the_makeup(): void
    {
        [$teacher, $booking] = $this->createIndividualBooking();

        $this->completedSession($booking, 1, now()->subDays(6), ClassSession::OUTCOME_ATTENDED, 100);
        $noShow = $this->completedSession($booking, 2, now()->subDays(5), ClassSession::OUTCOME_STUDENT_NO_SHOW, 0, compensation: 30);

        $first = app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(7), now()->subDays(5));
        $this->assertEquals(130.0, (float) $first->gross_amount);

        $this->completedSession($booking, 3, now()->subDays(4), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 4, now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);
        $makeup = $this->completedSession($booking, 2, now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100, makeupFor: $noShow);

        $report = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(400.0, $report['teacherRevenue']);
        $this->assertEquals(240.0, $report['platformRevenue']);

        $second = app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(4), now());
        $this->assertEquals(270.0, (float) $second->gross_amount);
        $this->assertDatabaseHas('payout_items', ['class_session_id' => $makeup->id, 'amount' => 70]);

        $after = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];
        $this->assertEquals(400.0, $after['inPayoutsAmount']);
        $this->assertEquals(240.0, $after['platformRevenue']);
    }

    /**
     * سلسلة تعويض: صُرف التعويض (30) على الأصلية، ثم فاتت تعويضيتها الأولى (بلا تعويض — تعويضية)،
     * ثم عُقدت الثانية — يُخصم منها التعويض المصروف (100 − 30) كما لو كانت الأولى. كانت تُصرف كاملة
     * فيأخذ المعلم 430. النتيجة: المعلم 400 والمنصة 240.
     */
    public function test_a_compensation_paid_on_the_original_is_deducted_from_a_later_makeup_in_the_chain(): void
    {
        [$teacher, $booking] = $this->createIndividualBooking();

        $this->completedSession($booking, 1, now()->subDays(8), ClassSession::OUTCOME_ATTENDED, 100);
        $noShow = $this->completedSession($booking, 2, now()->subDays(7), ClassSession::OUTCOME_STUDENT_NO_SHOW, 0, compensation: 30);

        $first = app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(9), now()->subDays(7));
        $this->assertEquals(130.0, (float) $first->gross_amount);

        $missedMakeup = $this->completedSession($booking, 2, now()->subDays(5), ClassSession::OUTCOME_STUDENT_NO_SHOW, 0, makeupFor: $noShow);
        $this->completedSession($booking, 3, now()->subDays(4), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 4, now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);
        $makeup = $this->completedSession($booking, 2, now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100, makeupFor: $missedMakeup);

        $report = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(400.0, $report['teacherRevenue']);
        $this->assertEquals(240.0, $report['platformRevenue']);

        $second = app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(6), now());
        $this->assertEquals(270.0, (float) $second->gross_amount);
        $this->assertDatabaseHas('payout_items', ['class_session_id' => $makeup->id, 'amount' => 70]);
        $this->assertDatabaseMissing('payout_items', ['class_session_id' => $missedMakeup->id]);

        $after = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];
        $this->assertEquals(400.0, $after['inPayoutsAmount']);
        $this->assertEquals(240.0, $after['platformRevenue']);
    }

    /**
     * المجموعة: التعويض مرة واحدة للجلسة أياً كان عدد حجوزاتها، لا لكل طالب. قيمة الجلسة الفائتة
     * (حجزان × (50 + 30)) للمنصة: 60 + 160 − 30 = 190، والمعلم 130 — المجموع 320 ما دفعه الطالبان.
     */
    public function test_fixed_compensation_is_paid_once_per_group_session(): void
    {
        $teacher = $this->createTeacher('school');
        $package = $this->createGroupPackage($teacher);
        $owner = $this->joinGroup($package);
        $second = $this->joinGroup($package);

        $this->groupSession($owner, [$second], now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);
        $this->groupSession($owner, [$second], now()->subDays(2), ClassSession::OUTCOME_STUDENT_NO_SHOW, 0, compensation: 30);

        $report = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(130.0, $report['teacherRevenue']);
        $this->assertEquals(190.0, $report['platformRevenue']);
        $this->assertEquals(130.0, (float) app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(4), now())->gross_amount);
    }

    /**
     * مجموعة بحجزين (50 للمعلم و30 للمنصة لكل حجز في الجلسة، جلستان لكل حجز): عُقدت، لم يحضر
     * الطلاب (50%)، غاب المعلم، وتعويضية الغياب عُقدت فملأت المكان الثاني. كل حجز صُرف منه
     * 125 من قيمة 100 (تعويض غياب الطلاب خارج السقف) — 25 لكلٍّ من هامش المنصة: 120 − 50.
     */
    public function test_group_attendance_outcomes_and_makeup_match_the_generated_payout(): void
    {
        $teacher = $this->createTeacher('school');
        $package = $this->createGroupPackage($teacher);
        $owner = $this->joinGroup($package);
        $second = $this->joinGroup($package);

        $sessions = [
            $this->groupSession($owner, [$second], now()->subDays(6), ClassSession::OUTCOME_ATTENDED, 100),
            $this->groupSession($owner, [$second], now()->subDays(5), ClassSession::OUTCOME_STUDENT_NO_SHOW, 50),
        ];
        $absent = $this->groupSession($owner, [$second], now()->subDays(4), ClassSession::OUTCOME_TEACHER_ABSENT, 0);
        $this->groupSession($owner, [$second], now()->subDays(2), ClassSession::OUTCOME_ATTENDED, 100, makeupFor: $absent);

        $report = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertSame(3, $report['sessionsCount']);
        $this->assertSame(1, $report['studentNoShowCount']);
        $this->assertSame(1, $report['teacherAbsentCount']);
        $this->assertEquals(250.0, $report['teacherRevenue']);
        $this->assertEquals(70.0, $report['platformRevenue']);

        $payout = app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(7), now());
        $this->assertEquals($report['teacherRevenue'], (float) $payout->gross_amount);
        $this->assertSame(count($sessions) + 1, $payout->sessions_count);
    }

    /**
     * مجموعة بحجزين (50 للمعلم و30 للمنصة لكل حجز في الجلسة، جلستان لكل حجز): الحجز الأول
     * حضر ثلاث جلسات (الثالثة زائدة — بيانات قديمة) فتسقط حصته منها من مستحق المعلم ومن
     * عائد المنصة معاً، وتُصرف حصة الحجز الثاني. عائد المنصة من كل حجز = ما دُفع لها (60)،
     * قبل توليد الكشف وبعده.
     */
    public function test_platform_revenue_excludes_booking_shares_beyond_the_cap_in_group_sessions(): void
    {
        $teacher = $this->createTeacher('school');
        $package = $this->createGroupPackage($teacher);
        $first = $this->joinGroup($package);
        $second = $this->joinGroup($package);

        $this->groupSession($first, [], now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);
        $this->groupSession($first, [$second], now()->subDays(2), ClassSession::OUTCOME_ATTENDED, 100);
        $this->groupSession($first, [$second], now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100);

        $before = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(200.0, $before['teacherRevenue']);
        $this->assertEquals(120.0, $before['platformRevenue']);

        app(PayoutService::class)->generateForPeriod($teacher, now()->subDays(4), now());

        $after = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(200.0, $after['inPayoutsAmount']);
        $this->assertEquals(120.0, $after['platformRevenue']);

        // نطاق يشمل الجلسة الأخيرة وحدها: الجلسات السابقة خارجه ما زالت تستهلك أماكن الحجز الأول
        $lastDay = app(TeacherSessionsReportService::class)->getReport(now()->subDay(), now(), null)['totals'];

        $this->assertEquals(50.0, $lastDay['teacherRevenue']);
        $this->assertEquals(30.0, $lastDay['platformRevenue']);
    }

    /**
     * غاب المعلم عن الثانية: قيمتها كاملة (100 + 60) للمنصة ما دامت بلا تعويضية — 180 + 160 = 340
     * والمعلم 300. تعويضية مجدولة (لم تُعقد بعد) تُسقطها عن المنصة فوراً، وبانعقادها تنتقل للمعلم:
     * 400 + 240. أرقام الفترة الماضية تتغيّر — نطاق يوم الغياب وحده يعرض قيمته للمنصة ثم صفراً.
     */
    public function test_teacher_absence_value_stays_with_the_platform_until_a_makeup_is_created(): void
    {
        [, $booking] = $this->createIndividualBooking();

        $this->completedSession($booking, 1, now()->subDays(6), ClassSession::OUTCOME_ATTENDED, 100);
        $absent = $this->completedSession($booking, 2, now()->subDays(5), ClassSession::OUTCOME_TEACHER_ABSENT, 0);
        $this->completedSession($booking, 3, now()->subDays(4), ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($booking, 4, now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);

        $reports = app(TeacherSessionsReportService::class);

        $before = $reports->getReport(null, null, null)['totals'];
        $this->assertSame(1, $before['teacherAbsentCount']);
        $this->assertEquals(300.0, $before['teacherRevenue']);
        $this->assertEquals(340.0, $before['platformRevenue']);

        $absenceDay = $reports->getReport(now()->subDays(5), now()->subDays(5), null);
        $this->assertCount(1, $absenceDay['rows']);
        $this->assertEquals(0.0, $absenceDay['totals']['teacherRevenue']);
        $this->assertEquals(160.0, $absenceDay['totals']['platformRevenue']);

        $makeup = $booking->sessions()->create([
            'teacher_id' => $booking->teacher_id,
            'sequence_no' => 2,
            'scheduled_at' => now()->addDay(),
            'duration_min' => 60,
            'status' => 'scheduled',
            'is_makeup' => true,
            'makeup_for_session_id' => $absent->id,
        ]);

        $scheduled = $reports->getReport(null, null, null)['totals'];
        $this->assertEquals(300.0, $scheduled['teacherRevenue']);
        $this->assertEquals(180.0, $scheduled['platformRevenue']);
        $this->assertEquals(0.0, $reports->getReport(now()->subDays(5), now()->subDays(5), null)['totals']['platformRevenue']);

        $makeup->update([
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
            'attendance_outcome' => ClassSession::OUTCOME_ATTENDED,
            'teacher_payout_percent' => 100,
        ]);
        SessionAttendee::create([
            'class_session_id' => $makeup->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        $held = $reports->getReport(null, null, null)['totals'];
        $this->assertEquals(400.0, $held['teacherRevenue']);
        $this->assertEquals(240.0, $held['platformRevenue']);
    }

    /** المجموعة: غياب المعلم بلا تعويضية — حجزان × (50 + 30) للمنصة: 60 + 160 = 220، والمعلم 100 */
    public function test_group_teacher_absence_value_stays_with_the_platform(): void
    {
        $teacher = $this->createTeacher('school');
        $package = $this->createGroupPackage($teacher);
        $owner = $this->joinGroup($package);
        $second = $this->joinGroup($package);

        $this->groupSession($owner, [$second], now()->subDays(3), ClassSession::OUTCOME_ATTENDED, 100);
        $this->groupSession($owner, [$second], now()->subDays(2), ClassSession::OUTCOME_TEACHER_ABSENT, 0);

        $report = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];

        $this->assertEquals(100.0, $report['teacherRevenue']);
        $this->assertEquals(220.0, $report['platformRevenue']);
    }

    /**
     * دورة 4 جلسات بتسجيلين (200 للمركز و100 للمنصة → 50 و25 للجلسة): غاب المركز عن الثالثة بلا
     * تعويضية فقيمتها (75) للمنصة — 75 + 75 = 150، والمركز 150. جلسة خامسة زائدة (بيانات قديمة)
     * غاب عنها أيضاً لا مكان لها في السقف، فلا قيمة تُنسب للمنصة عنها.
     */
    public function test_course_missed_session_value_goes_to_the_platform_within_the_cap(): void
    {
        $center = $this->createTeacher('training_center');
        $course = $this->createCourse($center, totalSessions: 4);
        $this->enroll($course);
        $this->enroll($course);

        $this->completedCourseSession($course, 1, now()->subDays(5));
        $this->completedCourseSession($course, 2, now()->subDays(4));
        $this->completedCourseSession($course, 3, now()->subDays(3), ClassSession::OUTCOME_TEACHER_ABSENT, 0);
        $this->completedCourseSession($course, 4, now()->subDays(2));

        $report = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];
        $this->assertEquals(150.0, $report['teacherRevenue']);
        $this->assertEquals(150.0, $report['platformRevenue']);

        $this->completedCourseSession($course, 5, now()->subDay(), ClassSession::OUTCOME_TEACHER_ABSENT, 0);

        $extra = app(TeacherSessionsReportService::class)->getReport(null, null, null)['totals'];
        $this->assertSame(2, $extra['teacherAbsentCount']);
        $this->assertEquals(150.0, $extra['teacherRevenue']);
        $this->assertEquals(150.0, $extra['platformRevenue']);
    }

    public function test_finance_endpoint_returns_the_report(): void
    {
        [, $booking] = $this->createIndividualBooking();
        $this->completedSession($booking, 1, now()->subDay(), ClassSession::OUTCOME_ATTENDED, 100);

        $admin = User::factory()->admin()->create();

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson('/api/reports/teacher-sessions')
            ->assertStatus(200)
            ->assertJsonPath('data.totals.sessionsCount', 1)
            ->assertJsonPath('data.totals.teacherRevenue', 100)
            ->assertJsonPath('data.totals.platformRevenue', 60);
    }

    /**
     * @return array{0: Teacher, 1: Booking}
     */
    private function createIndividualBooking(int $sessionsTotal = 4): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $subject = Subject::create(['code' => 'rp-'.uniqid(), 'name_ar' => 'مادة']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => $sessionsTotal,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160 * $sessionsTotal,
            'platform_revenue' => 60 * $sessionsTotal,
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
            'amount_paid' => 160 * $sessionsTotal,
            'teacher_amount' => 100 * $sessionsTotal,
            'platform_amount' => 60 * $sessionsTotal,
            'margin_percent_snapshot' => 60,
            'sessions_total' => $sessionsTotal,
            'sessions_remaining' => $sessionsTotal,
            'status' => 'confirmed',
        ]);

        return [$teacher, $booking];
    }

    private function createTeacher(string $type): Teacher
    {
        $user = User::factory()->teacher()->create();

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => $type, 'status' => 'verified']);
    }

    private function createCourse(Teacher $center, int $totalSessions): Course
    {
        $field = CourseField::create(['code' => 'rf-'.uniqid(), 'name_ar' => 'مجال']);

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

    /** تسجيل مدفوع: 100 للمركز و50 للمنصة */
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

    private function completedCourseSession(Course $course, int $sequence, $scheduledAt, string $outcome = ClassSession::OUTCOME_ATTENDED, float $percent = 100): ClassSession
    {
        return ClassSession::create([
            'course_id' => $course->id,
            'teacher_id' => $course->teacher_id,
            'sequence_no' => $sequence,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'completed',
            'attendance_outcome' => $outcome,
            'teacher_payout_percent' => $percent,
        ]);
    }

    private function createGroupPackage(Teacher $teacher): Package
    {
        $subject = Subject::create(['code' => 'rg-'.uniqid(), 'name_ar' => 'مادة']);

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

    /** حجز مدفوع: 100 للمعلم و60 للمنصة على جلستين */
    private function joinGroup(Package $package): Booking
    {
        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        return Booking::create([
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
            'status' => 'confirmed',
        ]);
    }

    /** @param  array<int, Booking>  $others */
    private function groupSession(Booking $owner, array $others, $scheduledAt, string $outcome, float $percent, ?ClassSession $makeupFor = null, ?float $compensation = null): ClassSession
    {
        $session = $owner->sessions()->create([
            'teacher_id' => $owner->teacher_id,
            'sequence_no' => $makeupFor?->sequence_no ?? $owner->sessions()->count() + 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'completed',
            'attendance_outcome' => $outcome,
            'teacher_payout_percent' => $percent,
            'teacher_compensation_amount' => $compensation,
            'is_makeup' => $makeupFor !== null,
            'makeup_for_session_id' => $makeupFor?->id,
        ]);

        foreach ([$owner, ...$others] as $booking) {
            SessionAttendee::create([
                'class_session_id' => $session->id,
                'student_id' => $booking->student_id,
                'booking_id' => $booking->id,
                'attendance' => 'registered',
            ]);
        }

        return $session;
    }

    private function completedSession(Booking $booking, int $sequence, $scheduledAt, string $outcome, float $percent, ?ClassSession $makeupFor = null, ?float $compensation = null): ClassSession
    {
        $session = $booking->sessions()->create([
            'teacher_id' => $booking->teacher_id,
            'sequence_no' => $sequence,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'completed',
            'attendance_outcome' => $outcome,
            'teacher_payout_percent' => $percent,
            'teacher_compensation_amount' => $compensation,
            'is_makeup' => $makeupFor !== null,
            'makeup_for_session_id' => $makeupFor?->id,
        ]);

        $attendee = SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        // سطر الحضور أُنشئ عند الحجز قبل الجلسة — وإلا عُدّ منضماً لاحقاً فاتته الأصلية ذات التعويضية
        SessionAttendee::whereKey($attendee->id)->update(['created_at' => $booking->created_at->copy()->subDays(30)]);

        return $session;
    }
}
