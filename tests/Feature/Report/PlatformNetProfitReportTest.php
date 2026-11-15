<?php

namespace Tests\Feature\Report;

use App\Exports\PlatformNetProfitExport;
use App\Models\AcademicSupporter;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Employee;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SupportPackage;
use App\Models\SupportPackagePurchase;
use App\Models\Teacher;
use App\Models\User;
use App\Services\EmployeePayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * صافي أرباح المنصة = أرباح حصص المعلمين + أرباح الدعم الأكاديمي − رواتب الموظفين، بنفس أرقام
 * كل تقرير، مع تفصيل شهري.
 */
class PlatformNetProfitReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_net_profit_combines_sessions_and_support_minus_salaries_per_month(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');

        // تشرين الأول: حصة منعقدة (60 للمنصة) + اشتراك دعم 1000 بنسبة داعم 60% (400 للمنصة)
        $booking = $this->createBooking();
        $this->completedSession($booking, 1, Carbon::parse('2026-10-10 10:00'));
        $this->supportPurchase(1000, 60, Carbon::parse('2026-10-12 10:00'));

        // تشرين الثاني: حصتان (120) + اشتراك 2000 بنسبة 50% (1000)
        $this->completedSession($booking, 2, Carbon::parse('2026-11-03 10:00'));
        $this->completedSession($booking, 3, Carbon::parse('2026-11-05 10:00'));
        $this->supportPurchase(2000, 50, Carbon::parse('2026-11-06 10:00'));

        // رواتب: 3000 لكل شهر
        Employee::create(['name' => 'سارة', 'base_salary' => 3000]);
        $payroll = app(EmployeePayrollService::class);
        $payroll->generateMonth('2026-10');
        $payroll->generateMonth('2026-11');

        $response = $this->withToken(User::factory()->admin()->create()->createToken('t')->plainTextToken)
            ->getJson('/api/reports/platform-profit?from=2026-10-01&to=2026-11-30')
            ->assertOk();

        $response->assertJsonPath('data.currency', 'AED')
            ->assertJsonPath('data.totals.sessionsProfit', 180)
            ->assertJsonPath('data.totals.supportProfit', 1400)
            ->assertJsonPath('data.totals.totalProfit', 1580)
            ->assertJsonPath('data.totals.salaries', 6000)
            ->assertJsonPath('data.totals.netProfit', -4420)
            ->assertJsonPath('data.months.0.month', '2026-10')
            ->assertJsonPath('data.months.0.netProfit', -2540)
            ->assertJsonPath('data.months.1.month', '2026-11')
            ->assertJsonPath('data.months.1.totalProfit', 1120);
    }

    public function test_defaults_to_current_month_and_finance_users_only(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');

        $this->withToken(User::factory()->admin()->create()->createToken('t')->plainTextToken)
            ->getJson('/api/reports/platform-profit')
            ->assertOk()
            ->assertJsonPath('data.filters.from', '2026-11-01')
            ->assertJsonPath('data.filters.to', '2026-11-20')
            ->assertJsonCount(1, 'data.months');

        $this->app['auth']->forgetGuards();
        $accountant = User::factory()->create(['role' => 'accountant']);
        $this->withToken($accountant->createToken('t')->plainTextToken)->getJson('/api/reports/platform-profit')->assertOk();

        $this->app['auth']->forgetGuards();
        $teacher = User::factory()->teacher()->create();
        $this->withToken($teacher->createToken('t')->plainTextToken)->getJson('/api/reports/platform-profit')->assertForbidden();
    }

    public function test_period_validation_and_excel_export(): void
    {
        Excel::fake();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/reports/platform-profit?from=2026-11-10&to=2026-11-01')->assertStatus(422);
        $this->withToken($token)->getJson('/api/reports/platform-profit?from=2024-01-01&to=2026-11-01')->assertStatus(422);

        $this->withToken($token)->get('/api/reports/platform-profit/export?from=2026-10-01&to=2026-11-30')->assertOk();

        Excel::assertDownloaded('platform-net-profit-2026-10-01-to-2026-11-30.xlsx', function (PlatformNetProfitExport $export) {
            $sheets = $export->sheets();
            $monthly = $sheets[1]->array();

            return array_map(fn ($s) => $s->title(), $sheets) === ['ملخص', 'تفصيل شهري']
                && count($monthly) === 3 // شهران + الإجمالي
                && end($monthly)[0] === 'الإجمالي';
        });
    }

    private function createBooking(): Booking
    {
        $teacher = Teacher::create(['user_id' => User::factory()->teacher()->create()->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => Subject::create(['code' => 'p-'.uniqid(), 'name_ar' => 'مادة'])->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 640,
            'platform_revenue' => 240,
            'status' => 'active',
            'approved_at' => now(),
        ]);
        $student = Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']);

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 640,
            'teacher_amount' => 400,
            'platform_amount' => 240,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'confirmed',
        ]);
        Booking::whereKey($booking->id)->update(['created_at' => Carbon::parse('2026-09-01')]);

        return $booking->fresh();
    }

    private function completedSession(Booking $booking, int $sequence, Carbon $scheduledAt): ClassSession
    {
        $session = $booking->sessions()->create([
            'teacher_id' => $booking->teacher_id,
            'sequence_no' => $sequence,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'completed',
            'attendance_outcome' => ClassSession::OUTCOME_ATTENDED,
            'teacher_payout_percent' => 100,
        ]);

        $attendee = SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);
        SessionAttendee::whereKey($attendee->id)->update(['created_at' => $booking->created_at]);

        return $session;
    }

    private function supportPurchase(float $price, float $supporterPercent, Carbon $confirmedAt): void
    {
        $supporter = AcademicSupporter::create(['user_id' => User::factory()->create(['role' => 'academic_support'])->id]);
        $supporterAmount = round($price * $supporterPercent / 100, 2);

        $package = SupportPackage::create([
            'academic_supporter_id' => $supporter->id,
            'title' => 'باقة دعم',
            'tier' => 'basic',
            'description' => 'وصف',
            'student_price' => $price,
            'supporter_profit_percent' => $supporterPercent,
            'platform_margin_percent' => 100 - $supporterPercent,
            'teacher_price' => $supporterAmount,
            'platform_revenue' => $price - $supporterAmount,
            'status' => 'active',
        ]);

        $student = Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'university']);

        SupportPackagePurchase::create([
            'student_id' => $student->id,
            'academic_supporter_id' => $supporter->id,
            'support_package_id' => $package->id,
            'amount' => $price,
            'status' => 'confirmed',
            'confirmed_at' => $confirmedAt,
        ]);
    }
}
