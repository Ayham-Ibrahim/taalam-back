<?php

namespace Tests\Feature\Report;

use App\Exports\AcademicSupportFinanceExport;
use App\Models\AcademicSupporter;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SupportPackage;
use App\Models\SupportPackagePurchase;
use App\Models\User;
use App\Services\AcademicSupportFinanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class AcademicSupportFinanceReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * باقة 1000 بنسبة داعم 60%: مستحقه 600 عن كل اشتراك مدفوع، والمنصة 400. اشتراك بكوبون
     * خصم 100 يدفع 900 — مستحق الداعم يبقى 600 والخصم تتحمّله المنصة (300). المعلَّق والمنتهي
     * لا يدخلان الحسابات.
     */
    public function test_report_splits_each_paid_purchase_between_supporter_and_platform(): void
    {
        $supporter = $this->createSupporter('سارة الداعمة');
        $math = Subject::create(['code' => 'as-'.uniqid(), 'name_ar' => 'رياضيات']);
        $physics = Subject::create(['code' => 'as-'.uniqid(), 'name_ar' => 'فيزياء']);
        $package = $this->createPackage($supporter, 1000, 60, [$math->id, $physics->id]);

        $this->purchase($package, now()->subDays(2));
        $this->purchase($package, now()->subDay(), discount: 100);
        $this->purchase($package, now(), status: 'pending_payment');
        $this->purchase($package, now(), status: 'expired');

        $report = app(AcademicSupportFinanceReportService::class)->getReport(null, null, null);

        $this->assertSame(2, $report['totals']['purchasesCount']);
        $this->assertEquals(1900.0, $report['totals']['studentsPaid']);
        $this->assertEquals(100.0, $report['totals']['discountAmount']);
        $this->assertEquals(1200.0, $report['totals']['supporterAmount']);
        $this->assertEquals(700.0, $report['totals']['platformAmount']);

        $this->assertCount(1, $report['supporters']);
        $this->assertSame('سارة الداعمة', $report['supporters'][0]['supporterName']);
        $this->assertEquals(1200.0, $report['supporters'][0]['supporterAmount']);

        $packageRow = $report['packages'][0];
        $this->assertEquals(60.0, $packageRow['supporterPercent']);
        $this->assertEquals(40.0, $packageRow['platformPercent']);
        $this->assertEqualsCanonicalizing(['رياضيات', 'فيزياء'], $packageRow['subjects']);

        $discounted = collect($report['purchases'])->firstWhere('discountAmount', 100.0);
        $this->assertEquals(900.0, $discounted['amountPaid']);
        $this->assertEquals(600.0, $discounted['supporterAmount']);
        $this->assertEquals(300.0, $discounted['platformAmount']);
    }

    public function test_report_filters_by_payment_date_and_supporter(): void
    {
        $first = $this->createSupporter('الداعم الأول');
        $second = $this->createSupporter('الداعم الثاني');
        $firstPackage = $this->createPackage($first, 500, 50);
        $secondPackage = $this->createPackage($second, 800, 75);

        $this->purchase($firstPackage, now()->subDays(20));
        $this->purchase($firstPackage, now()->subDays(2));
        $this->purchase($secondPackage, now()->subDay());

        $service = app(AcademicSupportFinanceReportService::class);

        $recent = $service->getReport(now()->subDays(5), now(), null);
        $this->assertSame(2, $recent['totals']['purchasesCount']);
        $this->assertSame(2, $recent['totals']['supportersCount']);

        $onlySecond = $service->getReport(null, null, $second->id);
        $this->assertSame(1, $onlySecond['totals']['purchasesCount']);
        $this->assertEquals(600.0, $onlySecond['totals']['supporterAmount']);
        $this->assertEquals(200.0, $onlySecond['totals']['platformAmount']);

        // خيارات الفلتر لا تتأثر بالفلتر نفسه
        $this->assertCount(2, $onlySecond['supporterOptions']);
    }

    public function test_deleted_supporters_and_packages_stay_in_the_report(): void
    {
        $supporter = $this->createSupporter('داعم محذوف');
        $package = $this->createPackage($supporter, 400, 50);
        $this->purchase($package, now()->subDay());

        $package->delete();
        $supporter->delete();

        $report = app(AcademicSupportFinanceReportService::class)->getReport(null, null, null);

        $this->assertSame('داعم محذوف', $report['purchases'][0]['supporterName']);
        $this->assertEquals(200.0, $report['totals']['supporterAmount']);
    }

    public function test_finance_users_can_view_the_report_and_others_cannot(): void
    {
        $this->purchase($this->createPackage($this->createSupporter('داعم'), 300, 50), now());

        $admin = User::factory()->admin()->create();
        $accountant = User::factory()->create(['role' => 'accountant']);
        $teacher = User::factory()->teacher()->create();

        foreach ([$admin, $accountant] as $user) {
            $this->withToken($user->createToken('t')->plainTextToken)
                ->getJson('/api/reports/academic-support')
                ->assertOk()
                ->assertJsonPath('data.totals.purchasesCount', 1);

            // الحارس يحتفظ بالمستخدم المصادَق بين طلبات الاختبار الواحد
            $this->app['auth']->forgetGuards();
        }

        $this->withToken($teacher->createToken('t')->plainTextToken)
            ->getJson('/api/reports/academic-support')
            ->assertForbidden();
    }

    public function test_export_downloads_a_workbook_with_all_report_sheets(): void
    {
        Excel::fake();

        $this->purchase($this->createPackage($this->createSupporter('داعم'), 300, 50), now());
        $admin = User::factory()->admin()->create();

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->get('/api/reports/academic-support/export')
            ->assertOk();

        Excel::assertDownloaded('academic-support-finance-report.xlsx', function (AcademicSupportFinanceExport $export) {
            $sheets = $export->sheets();
            $purchaseRows = $sheets[3]->array();

            return array_map(fn ($sheet) => $sheet->title(), $sheets) === ['ملخص', 'الداعمون', 'الباقات', 'تفاصيل الاشتراكات']
                // صف الاشتراك + صف الإجمالي
                && count($purchaseRows) === 2
                && $purchaseRows[1][0] === 'الإجمالي'
                && $purchaseRows[1][10] == 300;
        });
    }

    public function test_export_can_download_a_single_table(): void
    {
        Excel::fake();

        $this->purchase($this->createPackage($this->createSupporter('داعم'), 300, 50), now());
        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->get('/api/reports/academic-support/export?sheet=packages')
            ->assertOk();

        Excel::assertDownloaded('academic-support-packages.xlsx', function (AcademicSupportFinanceExport $export) {
            $sheets = $export->sheets();

            return count($sheets) === 1 && $sheets[0]->title() === 'الباقات';
        });

        $this->withToken($token)
            ->getJson('/api/reports/academic-support/export?sheet=bogus')
            ->assertUnprocessable();
    }

    private function createSupporter(string $name): AcademicSupporter
    {
        $user = User::factory()->create(['role' => 'academic_support', 'name' => $name]);

        return AcademicSupporter::create(['user_id' => $user->id]);
    }

    private function createPackage(AcademicSupporter $supporter, float $price, float $supporterPercent, array $subjectIds = []): SupportPackage
    {
        $supporterAmount = round($price * $supporterPercent / 100, 2);

        $package = SupportPackage::create([
            'academic_supporter_id' => $supporter->id,
            'title' => 'باقة '.uniqid(),
            'tier' => 'basic',
            'description' => 'وصف',
            'student_price' => $price,
            'supporter_profit_percent' => $supporterPercent,
            'platform_margin_percent' => 100 - $supporterPercent,
            'teacher_price' => $supporterAmount,
            'platform_revenue' => $price - $supporterAmount,
            'status' => 'active',
        ]);

        $package->subjects()->sync($subjectIds);

        return $package;
    }

    private function purchase(SupportPackage $package, $confirmedAt, float $discount = 0, string $status = 'confirmed'): SupportPackagePurchase
    {
        $student = Student::create([
            'user_id' => User::factory()->student()->create()->id,
            'education_type' => 'university',
        ]);

        return SupportPackagePurchase::create([
            'student_id' => $student->id,
            'academic_supporter_id' => $package->academic_supporter_id,
            'support_package_id' => $package->id,
            'amount' => (float) $package->student_price - $discount,
            'discount_amount' => $discount ?: null,
            'status' => $status,
            'confirmed_at' => $status === 'confirmed' ? $confirmedAt : null,
        ]);
    }
}
