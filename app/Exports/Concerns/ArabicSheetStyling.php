<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;

/**
 * ورقة عربية: اتجاه من اليمين لليسار، صف عناوين عريض بخلفية، وصف مجاميع اختياري بمعادلات SUM
 * أسفل البيانات (أعمدة totalColumns()) — يبقى صحيحاً لو عدّل المستخدم أرقاماً داخل Excel.
 * يُستخدم مع WithEvents + ShouldAutoSize + WithStrictNullComparison (وإلا تُكتب الأصفار خلايا فارغة).
 */
trait ArabicSheetStyling
{
    /** @return array<string> حروف الأعمدة التي تُجمع في صف المجاميع — فارغة: بلا صف مجاميع */
    protected function totalColumns(): array
    {
        return [];
    }

    protected function totalsLabel(): string
    {
        return 'الإجمالي';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->setRightToLeft(true);

                $lastColumn = $sheet->getHighestColumn();
                $lastRow = $sheet->getHighestRow();

                $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '4B6898']],
                ]);
                $sheet->freezePane('A2');

                if ($this->totalColumns() === [] || $lastRow < 2) {
                    return;
                }

                $totalsRow = $lastRow + 1;
                $sheet->setCellValue("A{$totalsRow}", $this->totalsLabel());
                foreach ($this->totalColumns() as $column) {
                    $sheet->setCellValue("{$column}{$totalsRow}", "=SUM({$column}2:{$column}{$lastRow})");
                }
                $sheet->getStyle("A{$totalsRow}:{$lastColumn}{$totalsRow}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EDF0F5']],
                ]);
            },
        ];
    }
}
