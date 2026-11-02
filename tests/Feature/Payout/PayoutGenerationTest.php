<?php

namespace Tests\Feature\Payout;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\Payout;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * زر توليد المستحقات (POST payouts/generate) — الطريق الوحيد لإنشاء الكشوف: كشف واحد لكل معلم له
 * مستحق، بكل جلساته المكتملة غير المصروفة حتى لحظة الضغط أياً كان تاريخها، بلا اختيار فترة.
 */
class PayoutGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-01 03:00:00'));
    }

    public function test_the_button_generates_all_unpaid_dues_until_now_for_every_teacher(): void
    {
        $first = $this->createTeacher();
        $this->completedSession($first, '2026-09-20 10:00', ClassSession::OUTCOME_ATTENDED, 100);
        $this->completedSession($first, '2026-10-20 10:00', ClassSession::OUTCOME_ATTENDED, 100);
        // اليوم نفسه قبل الضغط — يدخل أيضاً
        $this->completedSession($first, '2026-11-01 01:00', ClassSession::OUTCOME_ATTENDED, 100);

        $second = $this->createTeacher();
        $this->completedSession($second, '2026-10-12 10:00', ClassSession::OUTCOME_ATTENDED, 100);

        $this->as($this->adminToken())
            ->postJson('/api/payouts/generate')
            ->assertStatus(201)
            ->assertJsonPath('data.created', 2);

        $this->assertSame(2, Payout::count());

        $payout = Payout::where('teacher_id', $first->id)->sole();
        $this->assertSame('2026-09-20', $payout->period_start->toDateString());
        $this->assertSame('2026-11-01', $payout->period_end->toDateString());
        $this->assertEquals(300.0, (float) $payout->gross_amount);
        $this->assertSame(3, $payout->sessions_count);
        $this->assertSame('pending', $payout->status);

        $this->assertEquals(100.0, (float) Payout::where('teacher_id', $second->id)->sole()->gross_amount);
    }

    /** غاب عن كل جلساته — لا كشف فارغ بصفر */
    public function test_a_teacher_without_positive_dues_gets_no_payout(): void
    {
        $teacher = $this->createTeacher();
        $this->completedSession($teacher, '2026-10-05 10:00', ClassSession::OUTCOME_TEACHER_ABSENT, 0);

        $this->as($this->adminToken())
            ->postJson('/api/payouts/generate')
            ->assertStatus(200)
            ->assertJsonPath('data.created', 0);

        $this->assertSame(0, Payout::count());
    }

    /** ضغطة ثانية لا تُكرر ما صُرف — تأخذ فقط ما اكتمل بعد الأولى */
    public function test_a_second_press_only_takes_what_completed_after_the_first(): void
    {
        $teacher = $this->createTeacher();
        $this->completedSession($teacher, '2026-10-05 10:00', ClassSession::OUTCOME_ATTENDED, 100);

        $service = app(PayoutService::class);

        $this->assertSame(1, $service->generateAll());
        $this->assertSame(0, $service->generateAll());

        $this->travelTo(Carbon::parse('2026-11-10 12:00:00'));
        $this->completedSession($teacher, '2026-11-08 10:00', ClassSession::OUTCOME_ATTENDED, 100);

        $this->assertSame(1, $service->generateAll());

        $latest = Payout::latest('id')->first();
        $this->assertSame('2026-11-08', $latest->period_start->toDateString());
        $this->assertSame('2026-11-10', $latest->period_end->toDateString());
        $this->assertEquals(100.0, (float) $latest->gross_amount);
        $this->assertSame(2, Payout::count());
    }

    /** فلتر الشهر: كشف تتقاطع فترته مع الشهر — كشف سبتمبر–نوفمبر يظهر في أكتوبر، وكشف نوفمبر وحده لا */
    public function test_the_list_filters_payouts_by_month(): void
    {
        $teacher = $this->createTeacher();
        $this->completedSession($teacher, '2026-09-20 10:00', ClassSession::OUTCOME_ATTENDED, 100);
        app(PayoutService::class)->generateAll();

        $this->travelTo(Carbon::parse('2026-11-20 12:00:00'));
        $this->completedSession($teacher, '2026-11-10 10:00', ClassSession::OUTCOME_ATTENDED, 100);
        app(PayoutService::class)->generateAll();

        $token = $this->adminToken();

        $this->as($token)->getJson('/api/payouts?month=2026-10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.periodStart', '2026-09-20');

        $this->as($token)->getJson('/api/payouts?month=2026-11')->assertOk()->assertJsonCount(2, 'data');
        $this->as($token)->getJson('/api/payouts?month=2026-08')->assertOk()->assertJsonCount(0, 'data');
        $this->as($token)->getJson('/api/payouts?month=2026/10')->assertStatus(422);
    }

    public function test_only_finance_can_generate_payouts(): void
    {
        $teacher = $this->createTeacher();
        $this->completedSession($teacher, '2026-10-05 10:00', ClassSession::OUTCOME_ATTENDED, 100);

        $this->as($teacher->user->createToken('t')->plainTextToken)
            ->postJson('/api/payouts/generate')
            ->assertStatus(403);

        $this->assertSame(0, Payout::count());
    }

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('t')->plainTextToken;
    }

    private function createTeacher(): Teacher
    {
        $user = User::factory()->teacher()->create();

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);
    }

    /** حجز فردي جديد بعشر جلسات (100 للمعلم لكل جلسة) وجلسة واحدة مكتملة منه */
    private function completedSession(Teacher $teacher, string $scheduledAt, string $outcome, float $percent): ClassSession
    {
        $subject = Subject::create(['code' => 'mp-'.uniqid(), 'name_ar' => 'مادة']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 10,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 1600,
            'platform_revenue' => 600,
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
            'amount_paid' => 1600,
            'teacher_amount' => 1000,
            'platform_amount' => 600,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 10,
            'sessions_remaining' => 10,
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => Carbon::parse($scheduledAt),
            'duration_min' => 60,
            'status' => 'completed',
            'attendance_outcome' => $outcome,
            'teacher_payout_percent' => $percent,
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return $session;
    }
}
