<?php

namespace App\Exports;

use App\Exports\Concerns\ArabicSheetStyling;
use App\Models\EmployeeSalary;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/** كشف رواتب الموظفين — نفس فلاتر GET payroll (EmployeeSalary::scopeFilter)، مع صف مجاميع */
class EmployeePayrollExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    use ArabicSheetStyling;

    public function __construct(private readonly array $filters = []) {}

    public function query()
    {
        return EmployeeSalary::filter($this->filters)
            ->with('employee:id,name,job_title')
            ->orderBy('month', 'desc')
            ->orderBy('employee_id');
    }

    public function title(): string
    {
        return 'كشف الرواتب';
    }

    public function headings(): array
    {
        return [
            'الشهر',
            'الموظف',
            'المسمى الوظيفي',
            'الراتب الأساسي',
            'البونصات',
            'الخصومات',
            'الصافي',
            'العملة',
            'سعر الصرف',
            'الصافي بالدرهم',
            'الحالة',
            'تاريخ الدفع',
            'ملاحظات',
        ];
    }

    public function map($salary): array
    {
        return [
            $salary->month,
            $salary->employee?->name,
            $salary->employee?->job_title,
            (float) $salary->base_salary,
            (float) $salary->bonuses_total,
            (float) $salary->deductions_total,
            (float) $salary->net_amount,
            $salary->currency,
            (float) $salary->exchange_rate,
            (float) $salary->net_amount_aed,
            $salary->isPaid() ? 'مدفوع' : 'غير مدفوع',
            $salary->paid_at?->format('Y-m-d H:i'),
            $salary->notes,
        ];
    }

    protected function totalColumns(): array
    {
        // المبالغ الأصلية بعملات مختلفة لا تُجمع — المجموع لعمود الدرهم فقط
        return ['J'];
    }
}
