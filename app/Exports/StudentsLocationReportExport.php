<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use App\Services\StudentsLocationReportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * تقرير التسويق (الموقع × المنهج) ضمن نفس الفلاتر: حسب الموقع، حسب المنهج، حسب الصف، حسب المادة،
 * مصفوفات الموقع × المنهج/الصف/المادة، وقائمة الطلاب أنفسهم (للتواصل مع شريحة بعينها).
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
            $this->gradeSheet($report),
            $this->subjectSheet($report),
            $this->matrixSheet('الموقع × المنهج', $report['matrix'], fn (array $c) => $c['curriculum'] ?? 'بدون منهج'),
            $this->matrixSheet('الموقع × الصف', $report['gradeMatrix'], fn (array $c) => $c['grade'] ? 'الصف '.$c['grade'] : 'بدون صف'),
            $this->matrixSheet('الموقع × المادة', $report['subjectMatrix'], fn (array $c) => $c['subject'] ?? '—'),
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

    private function gradeSheet(array $report): ReportSheet
    {
        return new ReportSheet('حسب الصف', ['الصف', ...self::METRIC_HEADINGS], array_map(fn (array $row) => [
            $row['grade'] ? 'الصف '.$row['grade'] : 'بدون صف',
            ...$this->metrics($row),
        ], $report['byGrade']), ['الإجمالي', ...$this->metrics($report['totals'])]);
    }

    /** الطالب يُعد في كل مادة يدرسها — فلا صف إجمالي (مجموعه لا يساوي عدد الطلاب) */
    private function subjectSheet(array $report): ReportSheet
    {
        return new ReportSheet('حسب المادة', ['المادة', ...self::METRIC_HEADINGS], array_map(fn (array $row) => [
            $row['subject'] ?? '—',
            ...$this->metrics($row),
        ], $report['bySubject']));
    }

    private function matrixSheet(string $title, array $matrix, callable $columnLabel): ReportSheet
    {
        $columns = array_map($columnLabel, $matrix['columns']);

        $rows = array_map(fn (array $row) => [
            $row['country'] ?? 'غير محدد',
            $row['region'] ?? '—',
            ...$row['counts'],
            $row['total'],
        ], $matrix['rows']);

        $totals = array_map(fn (int $i) => array_sum(array_map(fn (array $row) => $row['counts'][$i], $matrix['rows'])), array_keys($matrix['columns']));

        return new ReportSheet($title, ['الدولة', 'الولاية', ...$columns, 'الإجمالي'], $rows, [
            'الإجمالي', '', ...$totals, array_sum(array_column($matrix['rows'], 'total')),
        ]);
    }

    private function studentsSheet(array $students): ReportSheet
    {
        return new ReportSheet('الطلاب', [
            'الطالب', 'البريد', 'الهاتف', 'هاتف ولي الأمر', 'الدولة', 'الولاية', 'نوع التعليم',
            'المنهج', 'المرحلة', 'الصف', 'المواد', 'تاريخ التسجيل', 'هل دفع؟', 'يدرس حالياً؟', 'إجمالي ما دفعه (AED)',
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
            $s['subjects'] ? implode('، ', $s['subjects']) : '—',
            $s['registeredAt'],
            $s['isPaying'] ? 'نعم' : 'لا',
            $s['hasRunningPackage'] ? 'نعم' : 'لا',
            $s['revenue'],
        ], $students), ['الإجمالي', count($students), '', '', '', '', '', '', '', '', '', '', '', '', array_sum(array_column($students, 'revenue'))]);
    }

    private function metrics(array $row): array
    {
        return [$row['students'], $row['paying'], $row['conversionRate'], $row['running'], $row['revenue']];
    }
}
