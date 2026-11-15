<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * صافي أرباح المنصة لفترة — ورقتان: ملخص (للمدير مباشرة) وتفصيل شهري بصف إجمالي. الأرقام
 * من PlatformNetProfitService كما هي، بالدرهم.
 */
class PlatformNetProfitExport implements WithMultipleSheets
{
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $totals = $this->report['totals'];
        $currency = $this->report['currency'];

        $summary = new ReportSheet('ملخص', ['البند', "القيمة ({$currency})"], [
            ['الفترة من', $this->report['filters']['from']],
            ['الفترة إلى', $this->report['filters']['to']],
            ['أرباح المنصة من حصص المعلمين (الباقات والدورات)', $totals['sessionsProfit']],
            ['أرباح المنصة من الدعم الأكاديمي', $totals['supportProfit']],
            ['إجمالي أرباح المنصة', $totals['totalProfit']],
            ['رواتب الموظفين', $totals['salaries']],
            ['صافي أرباح المنصة', $totals['netProfit']],
            ['عدد الحصص المنعقدة', $totals['sessionsCount']],
            ['عدد اشتراكات الدعم الأكاديمي', $totals['supportPurchasesCount']],
            ['تاريخ إصدار التقرير', now()->format('Y-m-d H:i')],
        ]);

        $months = new ReportSheet('تفصيل شهري', [
            'الشهر',
            'من',
            'إلى',
            'عدد الحصص',
            'أرباح الحصص',
            'اشتراكات الدعم',
            'أرباح الدعم الأكاديمي',
            'إجمالي الأرباح',
            'رواتب الموظفين',
            'صافي الربح',
        ], array_map(fn (array $row) => [
            $row['month'],
            $row['from'],
            $row['to'],
            $row['sessionsCount'],
            $row['sessionsProfit'],
            $row['supportPurchasesCount'],
            $row['supportProfit'],
            $row['totalProfit'],
            $row['salaries'],
            $row['netProfit'],
        ], $this->report['months']), [
            'الإجمالي', '', '',
            $totals['sessionsCount'],
            $totals['sessionsProfit'],
            $totals['supportPurchasesCount'],
            $totals['supportProfit'],
            $totals['totalProfit'],
            $totals['salaries'],
            $totals['netProfit'],
        ]);

        return [$summary, $months];
    }
}
