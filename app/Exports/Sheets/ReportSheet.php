<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ورقة تقرير عامة لملفات Excel متعددة الأوراق — عربية (من اليمين لليسار)، صف عناوين
 * بخلفية زرقاء (لون المنصة الأساسي) ونص أبيض، وصف إجمالي اختياري عريض بآخر الجدول.
 */
class ReportSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithStyles, WithTitle
{
    private const HEADER_COLOR = '3B5998';

    private const TOTALS_COLOR = 'E8ECF5';

    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
        private readonly ?array $totals = null,
    ) {}

    public function array(): array
    {
        return $this->totals === null ? $this->rows : [...$this->rows, $this->totals];
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function styles(Worksheet $sheet): array
    {
        // نطاق خلايا صريح (A1:H1) بدل رقم الصف حتى لا يمتد التلوين على كامل عرض الورقة
        $lastColumn = Coordinate::stringFromColumnIndex(max(count($this->headings), 1));

        $styles = ["A1:{$lastColumn}1" => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_COLOR]],
        ]];

        if ($this->totals !== null) {
            // +2: صف العناوين ثم الصفوف، والإجمالي بعدها مباشرة
            $totalsRow = count($this->rows) + 2;
            $styles["A{$totalsRow}:{$lastColumn}{$totalsRow}"] = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::TOTALS_COLOR]],
            ];
        }

        return $styles;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $event->sheet->getDelegate()->setRightToLeft(true),
        ];
    }
}
