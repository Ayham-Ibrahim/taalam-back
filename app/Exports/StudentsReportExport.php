<?php

namespace App\Exports;

use App\Exports\Sheets\ReportSheet;
use App\Services\StudentsReportService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * تقرير الطلاب والإحصاءات — نفس أرقام StudentsReportService بثلاث أوراق: الطلاب الذين انتهت
 * باقاتهم ولم يجددوا، توزيع المناهج ونوع التعليم، والطلاب النشطون للأسبوع المختار حسب المرحلة.
 * مع $only (expired|curricula|weekly) يُصدَّر جدول واحد فقط.
 */
class StudentsReportExport implements WithMultipleSheets
{
    public const SINGLE_SHEETS = ['expired', 'curricula', 'weekly'];

    private const EDUCATION_TYPE_LABELS = ['school' => 'مدرسي', 'university' => 'جامعي', 'training' => 'تدريب'];

    private const END_REASON_LABELS = ['sessions_finished' => 'انتهت جلساتها', 'validity_expired' => 'انتهت الصلاحية'];

    public function __construct(
        private readonly StudentsReportService $reportService,
        private readonly ?string $weekStart = null,
        private readonly ?string $only = null,
    ) {}

    public function sheets(): array
    {
        $report = $this->reportService->getReport($this->weekStart ? Carbon::parse($this->weekStart) : null);

        return match ($this->only) {
            'expired' => [$this->expiredSheet($report['expiredWithoutRenewal'])],
            'curricula' => [$this->curriculaSheet($report)],
            'weekly' => [$this->weeklySheet($report['weeklyActive'])],
            default => [
                $this->expiredSheet($report['expiredWithoutRenewal']),
                $this->curriculaSheet($report),
                $this->weeklySheet($report['weeklyActive']),
            ],
        };
    }

    private function expiredSheet(array $rows): ReportSheet
    {
        return new ReportSheet('انتهت باقاتهم', [
            'الطالب',
            'البريد',
            'الهاتف',
            'هاتف ولي الأمر',
            'المرحلة',
            'المنهج',
            'آخر باقة',
            'المعلم',
            'سبب الانتهاء',
            'تاريخ الانتهاء',
            'أيام منذ الانتهاء',
            'عدد الباقات المنتهية',
        ], array_map(fn (array $row) => [
            $row['name'],
            $row['email'],
            $row['phone'],
            $row['guardianPhone'],
            $row['stage'],
            $row['curriculum'],
            $row['lastPackage'],
            $row['lastTeacher'],
            self::END_REASON_LABELS[$row['endReason']] ?? $row['endReason'],
            $row['endedAt'],
            $row['daysSinceEnded'],
            $row['endedPackagesCount'],
        ], $rows), ['الإجمالي', count($rows), '', '', '', '', '', '', '', '', '', '']);
    }

    private function curriculaSheet(array $report): ReportSheet
    {
        $rows = array_map(fn (array $row) => [
            'المنهج',
            $row['name'] ?? 'غير محدد',
            $row['studentsCount'],
        ], $report['curricula']);

        foreach ($report['educationTypes'] as $row) {
            $rows[] = ['نوع التعليم', self::EDUCATION_TYPE_LABELS[$row['type']] ?? 'غير محدد', $row['studentsCount']];
        }

        return new ReportSheet('المناهج', ['التصنيف', 'البند', 'عدد الطلاب'], $rows, ['إجمالي الطلاب', '', $report['totals']['studentsCount']]);
    }

    private function weeklySheet(array $weekly): ReportSheet
    {
        $rows = $weekly['rows'];

        return new ReportSheet("النشطون {$weekly['weekStart']}", [
            'المرحلة',
            'نوع التعليم',
            'إجمالي الطلاب',
            'النشطون هذا الأسبوع',
            'النشطون الأسبوع السابق',
            'نسبة النشاط %',
        ], array_map(fn (array $row) => [
            $row['name'] ?? 'بدون مرحلة',
            self::EDUCATION_TYPE_LABELS[$row['educationType']] ?? 'غير محدد',
            $row['totalStudents'],
            $row['activeStudents'],
            $row['previousWeekActive'],
            $row['totalStudents'] ? round($row['activeStudents'] / $row['totalStudents'] * 100, 1) : 0,
        ], $rows), [
            'الإجمالي ('.$weekly['weekStart'].' → '.$weekly['weekEnd'].')',
            '',
            array_sum(array_column($rows, 'totalStudents')),
            $weekly['totalActive'],
            $weekly['previousWeekTotalActive'],
            '',
        ]);
    }
}
