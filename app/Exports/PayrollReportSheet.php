<?php

namespace App\Exports;

use App\Exports\Concerns\ArabicSheetStyling;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/** ورقة واحدة من التقرير الشامل (حسب الموظف/حسب الشهر) — صفوف جاهزة من EmployeePayrollService::report */
class PayrollReportSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    use ArabicSheetStyling;

    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $totalColumns = [],
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }

    protected function totalColumns(): array
    {
        return $this->totalColumns;
    }
}
