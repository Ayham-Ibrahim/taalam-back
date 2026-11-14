<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use App\Services\StudentsLocationReportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * تقرير التسويق (الموقع × المنهج) بأربع أوراق ضمن نفس الفلاتر: حسب الموقع، حسب المنهج،
 * مصفوفة الموقع × المنهج، وقائمة الطلاب أنفسهم (للتواصل مع شريحة بعينها).
 */
class StudentsLocationReportExport implements WithMultipleSheets
{
    private const EDUCATION_TYPE_LABELS = ['school' => 'مدرسي', 'university' => 'جامعي', 'training' => 'تدريب'];

    private const METRIC_HEADINGS = ['عدد الطلاب', 'عدد من دفع', 'نسبة من دفع منهم %', 'يدرسون حالياً', 'إجمالي ما دفعوه (AED)'];

    public function __construct(
        private readonly StudentsLocationReportService $reportService,
        private readonly array $filters,
    ) {}

    public function sheets(): array
    {
        $report = $this->reportService->getReport($this->filters);

        return [
            $this->locationSheet($report),
            $this->curriculumSheet($report),
            $this->matrixSheet($report['matrix']),
            $this->studentsSheet($this->reportService->allStudents($this->filters)),
        ];
    }

    private function locationSheet(array $report): ReportSheet
    {
        return new ReportSheet('حسب الموقع', ['الدولة', 'الولاية', ...self::METRIC_HEADINGS], array_map(fn (array $row) => [
            $row['country'] ?? 'غير محدد',
            $row['region'] ?? '—',
            ...$this->metrics($row),
        ], $report['byLocation']), ['الإجمالي', '', ...$this->metrics($report['totals'])]);
    }

    private function curriculumSheet(array $report): ReportSheet
    {
        return new ReportSheet('حسب المنهج', ['المنهج', ...self::METRIC_HEADINGS], array_map(fn (array $row) => [
            $row['curriculum'] ?? 'بدون منهج',
            ...$this->metrics($row),
        ], $report['byCurriculum']), ['الإجمالي', ...$this->metrics($report['totals'])]);
    }

    private function matrixSheet(array $matrix): ReportSheet
    {
        $columns = array_map(fn (array $c) => $c['curriculum'] ?? 'بدون منهج', $matrix['columns']);

        $rows = array_map(fn (array $row) => [
            $row['country'] ?? 'غير محدد',
            $row['region'] ?? '—',
            ...$row['counts'],
            $row['total'],
        ], $matrix['rows']);

        $totals = array_map(fn (int $i) => array_sum(array_map(fn (array $row) => $row['counts'][$i], $matrix['rows'])), array_keys($matrix['columns']));

        return new ReportSheet('الموقع × المنهج', ['الدولة', 'الولاية', ...$columns, 'الإجمالي'], $rows, [
            'الإجمالي', '', ...$totals, array_sum(array_column($matrix['rows'], 'total')),
        ]);
    }

    private function studentsSheet(array $students): ReportSheet
    {
        return new ReportSheet('الطلاب', [
            'الطالب', 'البريد', 'الهاتف', 'هاتف ولي الأمر', 'الدولة', 'الولاية', 'نوع التعليم',
            'المنهج', 'المرحلة', 'الصف', 'تاريخ التسجيل', 'هل دفع؟', 'يدرس حالياً؟', 'إجمالي ما دفعه (AED)',
        ], array_map(fn (array $s) => [
            $s['name'],
            $s['email'],
            $s['phone'],
            $s['guardianPhone'],
            $s['country'] ?? 'غير محدد',
            $s['region'] ?? '—',
            self::EDUCATION_TYPE_LABELS[$s['educationType']] ?? 'غير مكتمل',
            $s['curriculum'] ?? '—',
            $s['stage'] ?? '—',
            $s['grade'] ?? '—',
            $s['registeredAt'],
            $s['isPaying'] ? 'نعم' : 'لا',
            $s['hasRunningPackage'] ? 'نعم' : 'لا',
            $s['revenue'],
        ], $students), ['الإجمالي', count($students), '', '', '', '', '', '', '', '', '', '', '', array_sum(array_column($students, 'revenue'))]);
    }

    private function metrics(array $row): array
    {
        return [$row['students'], $row['paying'], $row['conversionRate'], $row['running'], $row['revenue']];
    }
}
