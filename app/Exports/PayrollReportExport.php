<?php

namespace App\Exports;

use App\Services\EmployeePayrollService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * التقرير الشامل كملف واحد بثلاث أوراق: حسب الموظف، حسب الشهر، وتفاصيل الكشوف — نفس أرقام
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
            round($m['netTotal'] - $m['paidAmount'], 2),
        ])->all();

        return [
            new PayrollReportSheet(
                'حسب الموظف',
                ['الموظف', 'المسمى الوظيفي', 'الحالة', 'عدد الأشهر', 'الأساسي', 'البونصات', 'الخصومات', 'الصافي', 'المدفوع', 'المتبقي'],
                $byEmployee,
                ['D', 'E', 'F', 'G', 'H', 'I', 'J'],
            ),
            new PayrollReportSheet(
                'حسب الشهر',
                ['الشهر', 'عدد الموظفين', 'الأساسي', 'البونصات', 'الخصومات', 'الصافي', 'المدفوع', 'المتبقي'],
                $byMonth,
                ['C', 'D', 'E', 'F', 'G', 'H'],
            ),
            new EmployeePayrollExport(array_filter(['from' => $from, 'to' => $to, 'employee_id' => $employeeId])),
        ];
    }
}
