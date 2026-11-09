<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use App\Services\AcademicSupportFinanceReportService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * التقرير المالي الشامل للدعم الأكاديمي — نفس أرقام AcademicSupportFinanceReportService
 * بأربع أوراق: ملخص، الداعمون (التزامات المنصة لكل داعم)، الباقات (الداعم والمواد والنسب)،
 * وتفصيل كل اشتراك مدفوع — كل جدول ينتهي بصف إجمالي لأعمدته الرقمية. مع $only (supporters|packages|purchases) يُصدَّر جدول واحد فقط
 * (زر التصدير داخل كل تاب بالواجهة).
 */
class AcademicSupportFinanceExport implements WithMultipleSheets
{
    public const SINGLE_SHEETS = ['supporters', 'packages', 'purchases'];

    private const TIER_LABELS = ['basic' => 'أساسية', 'premium' => 'شاملة'];

    public function __construct(
        private readonly AcademicSupportFinanceReportService $reportService,
        private readonly array $filters = [],
        private readonly ?string $only = null,
    ) {}

    public function sheets(): array
    {
        $report = $this->reportService->getReport(
            ! empty($this->filters['from']) ? Carbon::parse($this->filters['from']) : null,
            ! empty($this->filters['to']) ? Carbon::parse($this->filters['to']) : null,
            ! empty($this->filters['supporter_id']) ? (int) $this->filters['supporter_id'] : null,
        );

        return match ($this->only) {
            'supporters' => [$this->supportersSheet($report['supporters'])],
            'packages' => [$this->packagesSheet($report['packages'])],
            'purchases' => [$this->purchasesSheet($report['purchases'])],
            default => $this->allSheets($report),
        };
    }

    private function allSheets(array $report): array
    {
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
        ], $supporters), [
            'الإجمالي',
            $this->sum($supporters, 'packagesCount'),
            '',
            $this->sum($supporters, 'purchasesCount'),
            $this->sum($supporters, 'studentsPaid'),
            $this->sum($supporters, 'discountAmount'),
            $this->sum($supporters, 'supporterAmount'),
            $this->sum($supporters, 'platformAmount'),
        ]);
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
        ], $packages), [
            'الإجمالي', '', '', '', '', '', '',
            $this->sum($packages, 'purchasesCount'),
            $this->sum($packages, 'studentsPaid'),
            $this->sum($packages, 'discountAmount'),
            $this->sum($packages, 'supporterAmount'),
            $this->sum($packages, 'platformAmount'),
        ]);
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
        ], $purchases), [
            'الإجمالي', '', '', '', '', '', '',
            $this->sum($purchases, 'listPrice'),
            '',
            $this->sum($purchases, 'discountAmount'),
            $this->sum($purchases, 'amountPaid'),
            '',
            $this->sum($purchases, 'supporterAmount'),
            '',
            $this->sum($purchases, 'platformAmount'),
            '',
        ]);
    }

    /** مجموع عمود لصف الإجمالي — القيم الفارغة (مثل خصم غير موجود) تُحسب صفراً */
    private function sum(array $rows, string $key): float|int
    {
        return round(array_sum(array_map(fn (array $row) => $row[$key] ?? 0, $rows)), 2);
    }
}
