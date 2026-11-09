<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use App\Services\AcademicSupportFinanceReportService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * التقرير المالي الشامل للدعم الأكاديمي — نفس أرقام AcademicSupportFinanceReportService
 * بأربع أوراق: ملخص، الداعمون (التزامات المنصة لكل داعم)، الباقات (الداعم والمواد والنسب)،
 * وتفصيل كل اشتراك مدفوع.
 */
class AcademicSupportFinanceExport implements WithMultipleSheets
{
    private const TIER_LABELS = ['basic' => 'أساسية', 'premium' => 'شاملة'];

    public function __construct(
        private readonly AcademicSupportFinanceReportService $reportService,
        private readonly array $filters = [],
    ) {}

    public function sheets(): array
    {
        $report = $this->reportService->getReport(
            ! empty($this->filters['from']) ? Carbon::parse($this->filters['from']) : null,
            ! empty($this->filters['to']) ? Carbon::parse($this->filters['to']) : null,
            ! empty($this->filters['supporter_id']) ? (int) $this->filters['supporter_id'] : null,
        );

        return [
            $this->summarySheet($report),
            $this->supportersSheet($report['supporters']),
            $this->packagesSheet($report['packages']),
            $this->purchasesSheet($report['purchases']),
        ];
    }

    private function summarySheet(array $report): ReportSheet
    {
        $totals = $report['totals'];
        $supporterName = null;

        if (! empty($this->filters['supporter_id'])) {
            $supporterName = collect($report['supporterOptions'])->firstWhere('id', (int) $this->filters['supporter_id'])['name'] ?? null;
        }

        return new ReportSheet('ملخص', ['البند', 'القيمة'], [
            ['الفترة من', $this->filters['from'] ?? 'البداية'],
            ['الفترة إلى', $this->filters['to'] ?? 'اليوم'],
            ['الداعم', $supporterName ?? 'جميع الداعمين'],
            ['عدد الاشتراكات المدفوعة', $totals['purchasesCount']],
            ['عدد الداعمين', $totals['supportersCount']],
            ['إجمالي ما دفعه الطلاب', $totals['studentsPaid']],
            ['إجمالي الخصومات (كوبونات)', $totals['discountAmount']],
            ['إجمالي مستحقات الداعمين (التزامات المنصة)', $totals['supporterAmount']],
            ['صافي أرباح المنصة', $totals['platformAmount']],
            ['تاريخ إصدار التقرير', now()->format('Y-m-d H:i')],
        ]);
    }

    private function supportersSheet(array $supporters): ReportSheet
    {
        return new ReportSheet('الداعمون', [
            'الداعم',
            'عدد الباقات',
            'المواد',
            'عدد الاشتراكات',
            'ما دفعه الطلاب',
            'الخصومات',
            'مستحق الداعم (التزام المنصة)',
            'أرباح المنصة',
        ], array_map(fn (array $row) => [
            $row['supporterName'],
            $row['packagesCount'],
            implode('، ', $row['subjects']),
            $row['purchasesCount'],
            $row['studentsPaid'],
            $row['discountAmount'],
            $row['supporterAmount'],
            $row['platformAmount'],
        ], $supporters));
    }

    private function packagesSheet(array $packages): ReportSheet
    {
        return new ReportSheet('الباقات', [
            'الباقة',
            'الفئة',
            'الداعم المسؤول',
            'المواد',
            'سعر الباقة',
            'نسبة الداعم %',
            'نسبة المنصة %',
            'عدد الاشتراكات',
            'ما دفعه الطلاب',
            'الخصومات',
            'مستحق الداعم',
            'أرباح المنصة',
        ], array_map(fn (array $row) => [
            $row['packageTitle'],
            self::TIER_LABELS[$row['tier']] ?? $row['tier'],
            $row['supporterName'],
            implode('، ', $row['subjects']),
            $row['listPrice'],
            $row['supporterPercent'],
            $row['platformPercent'],
            $row['purchasesCount'],
            $row['studentsPaid'],
            $row['discountAmount'],
            $row['supporterAmount'],
            $row['platformAmount'],
        ], $packages));
    }

    private function purchasesSheet(array $purchases): ReportSheet
    {
        return new ReportSheet('تفاصيل الاشتراكات', [
            'رقم الاشتراك',
            'تاريخ الدفع',
            'الطالب',
            'الداعم',
            'الباقة',
            'الفئة',
            'المواد',
            'سعر الباقة',
            'كود الخصم',
            'الخصم',
            'المبلغ المدفوع',
            'نسبة الداعم %',
            'مستحق الداعم',
            'نسبة المنصة %',
            'أرباح المنصة',
            'العملة',
        ], array_map(fn (array $row) => [
            $row['purchaseId'],
            $row['confirmedAt'],
            $row['studentName'],
            $row['supporterName'],
            $row['packageTitle'],
            self::TIER_LABELS[$row['tier']] ?? $row['tier'],
            implode('، ', $row['subjects']),
            $row['listPrice'],
            $row['couponCode'],
            $row['discountAmount'],
            $row['amountPaid'],
            $row['supporterPercent'],
            $row['supporterAmount'],
            $row['platformPercent'],
            $row['platformAmount'],
            $row['currency'],
        ], $purchases));
    }
}
