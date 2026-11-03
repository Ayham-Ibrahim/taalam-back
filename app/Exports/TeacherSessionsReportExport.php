<?php

namespace App\Exports;

use App\Services\TeacherSessionsReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * صف واحد لكل معلم (بيانات مُجمَّعة أصلاً من TeacherSessionsReportService، لا
 * استعلام خام) — FromCollection لا FromQuery عمداً، خلافاً لـ PayoutsExport،
 * لأن عدد المعلمين صغير مقارنة بعدد الحجوزات/المستحقات.
 */
class TeacherSessionsReportExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly TeacherSessionsReportService $reportService,
        private readonly array $filters = [],
    ) {}

    public function collection(): Collection
    {
        $report = $this->reportService->getReport(
            ! empty($this->filters['from']) ? Carbon::parse($this->filters['from']) : null,
            ! empty($this->filters['to']) ? Carbon::parse($this->filters['to']) : null,
            ! empty($this->filters['teacher_id']) ? (int) $this->filters['teacher_id'] : null,
        );

        return collect($report['rows']);
    }

    public function headings(): array
    {
        return [
            'المعلم',
            'عدد الحصص المحقَّقة',
            'منها لم يحضر الطلاب',
            'غياب المعلم',
            'مستحق المعلم',
            'مُدرَج في كشوف',
            'لم يُدرَج بعد',
            'تسويات تسجيل متأخر',
            'عائد المنصة',
        ];
    }

    public function map($row): array
    {
        return [
            $row['teacherName'],
            $row['sessionsCount'],
            $row['studentNoShowCount'],
            $row['teacherAbsentCount'],
            $row['teacherRevenue'],
            $row['inPayoutsAmount'],
            $row['notInPayoutsAmount'],
            $row['settlementsAmount'],
            $row['platformRevenue'],
        ];
    }
}
