<?php

namespace App\Exports;

use App\Models\Employee;
use App\Services\EmployeePayrollService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * التقرير الشامل (مبالغه بالدرهم) كملف واحد بثلاث أوراق: حسب الموظف، حسب الشهر، وتفاصيل الكشوف — نفس أرقام
 * GET payroll/report لأنه مبني على EmployeePayrollService::report ونفس الفلاتر.
 */
class PayrollReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly EmployeePayrollService $payrollService,
        private readonly array $filters = [],
    ) {}

    public function sheets(): array
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;
        $employeeId = ! empty($this->filters['employee_id']) ? (int) $this->filters['employee_id'] : null;

        $report = $this->payrollService->report($from, $to, $employeeId);

        $byEmployee = collect($report['rows'])->map(fn (array $r) => [
            $r['employeeName'],
            $r['jobTitle'],
            $r['isActive'] ? 'نشط' : 'غير نشط',
            $r['monthsCount'],
            $r['currency'] ?? 'متعددة',
            $r['original']['netTotal'] ?? null,
            $r['baseTotal'],
            $r['bonusesTotal'],
            $r['deductionsTotal'],
            $r['netTotal'],
            $r['paidAmount'],
            $r['outstandingAmount'],
        ])->all();

        $byMonth = collect($report['months'])->map(fn (array $m) => [
            $m['month'],
            $m['employeesCount'],
            $m['baseTotal'],
            $m['bonusesTotal'],
            $m['deductionsTotal'],
            $m['netTotal'],
            $m['paidAmount'],
            $m['outstandingAmount'],
            collect($m['rates'])->map(fn ($rate, $currency) => $this->formatRate($currency, $rate))->implode(' | '),
        ])->all();

        return [
            new PayrollReportSheet(
                'حسب الموظف',
                ['الموظف', 'المسمى الوظيفي', 'الحالة', 'عدد الأشهر', 'العملة', 'الصافي بعملة الموظف', 'الأساسي (د.إ)', 'البونصات (د.إ)', 'الخصومات (د.إ)', 'الصافي (د.إ)', 'المدفوع (د.إ)', 'المتبقي (د.إ)'],
                $byEmployee,
                ['D', 'G', 'H', 'I', 'J', 'K', 'L'],
            ),
            new PayrollReportSheet(
                'حسب الشهر',
                ['الشهر', 'عدد الموظفين', 'الأساسي (د.إ)', 'البونصات (د.إ)', 'الخصومات (د.إ)', 'الصافي (د.إ)', 'المدفوع (د.إ)', 'المتبقي (د.إ)', 'أسعار الصرف'],
                $byMonth,
                ['C', 'D', 'E', 'F', 'G', 'H'],
            ),
            new EmployeePayrollExport(array_filter(['from' => $from, 'to' => $to, 'employee_id' => $employeeId])),
        ];
    }

    /** نفس عرض الواجهة: الجنيه معكوساً ("1 AED = 13.6 EGP") لأن سعره المباشر رقم صغير يصعب قراءته */
    private function formatRate(string $currency, float $rate): string
    {
        return $currency === 'EGP'
            ? '1 '.Employee::BASE_CURRENCY.' = '.round(1 / $rate, 4)." {$currency}"
            : "1 {$currency} = ".round($rate, 6).' '.Employee::BASE_CURRENCY;
    }
}
