<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Curriculum;
use App\Models\Region;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تقرير التسويق: توزيع الطلاب حسب الموقع (دولة ← ولاية) × المنهج — لتوجيه الحملات.
 * لكل مجموعة: عدد الطلاب، من دفع فعلاً (حجز مدفوع بمبلغ > 0 — المجانية لا تُحسب)،
 * من له باقة جارية الآن (confirmed/active)، والإيراد (amount_paid للحجوزات المدفوعة).
 *
 * الفلاتر (كلها اختيارية): الدولة، الولاية، المنهج، نوع التعليم، وفترة التسجيل
 * (students.created_at — أيام كاملة بتوقيت المنصة REPORT_TIMEZONE).
 */
class StudentsLocationReportService
{
    private const PAID_STATUSES = ['confirmed', 'active', 'completed'];

    private const RUNNING_STATUSES = ['confirmed', 'active'];

    public function getReport(array $filters): array
    {
        $groups = $this->groupedRows($filters);
        $names = $this->names();

        $byLocation = $this->sumBy($groups, fn ($g) => $g->country_id.':'.$g->region_id)
            ->map(fn (array $sum, string $key) => $this->locationLabel($groups->first(fn ($g) => $g->country_id.':'.$g->region_id === $key), $names) + $sum)
            ->sortByDesc('students')
            ->values();

        $byCurriculum = $this->sumBy($groups, fn ($g) => (string) $g->curriculum_id)
            ->map(fn (array $sum, string $key) => [
                'curriculumId' => $key === '' ? null : (int) $key,
                'curriculum' => $key === '' ? null : $names['curricula'][(int) $key] ?? null,
            ] + $sum)
            ->sortByDesc('students')
            ->values();

        return [
            'totals' => $this->sum($groups) + [
                'withoutLocation' => (int) $groups->whereNull('country_id')->sum('students'),
            ],
            'byLocation' => $byLocation->all(),
            'byCurriculum' => $byCurriculum->all(),
            'matrix' => $this->matrix($groups, $byLocation, $byCurriculum),
        ];
    }

    /** قائمة الطلاب ضمن نفس الفلاتر — لتنزيل/مراجعة أفراد شريحة معيّنة */
    public function students(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->studentsQuery($filters)
            ->orderByDesc('students.id')
            ->paginate($perPage)
            ->through(fn (Student $s) => $this->studentRow($s));
    }

    /** كل الطلاب ضمن الفلاتر بلا صفحات — للتصدير */
    public function allStudents(array $filters): array
    {
        return $this->studentsQuery($filters)
            ->orderByDesc('students.id')
            ->get()
            ->map(fn (Student $s) => $this->studentRow($s))
            ->all();
    }

    private function studentsQuery(array $filters): Builder
    {
        return $this->filtered($filters)
            ->leftJoinSub($this->bookingStats(), 'bs', 'bs.student_id', '=', 'students.id')
            ->select('students.*', 'bs.paying', 'bs.running', 'bs.revenue')
            ->with([
                'user:id,name,email,phone,created_at',
                'locationCountry:id,name_ar',
                'region:id,name_ar',
                'curriculum:id,name_ar',
                'stage:id,name_ar',
            ]);
    }

    private function studentRow(Student $s): array
    {
        return [
            'studentId' => $s->id,
            'name' => $s->user?->name,
            'email' => $s->user?->email,
            'phone' => $s->user?->phone,
            'guardianPhone' => $s->guardian_phone,
            'country' => $s->locationCountry?->name_ar,
            'region' => $s->region?->name_ar,
            'educationType' => $s->education_type,
            'curriculum' => $s->curriculum?->name_ar,
            'stage' => $s->stage?->name_ar,
            'grade' => $s->grade,
            'registeredAt' => $s->created_at?->copy()->setTimezone(StudentsReportService::REPORT_TIMEZONE)->toDateString(),
            'isPaying' => (bool) $s->paying,
            'hasRunningPackage' => (bool) $s->running,
            'revenue' => round((float) $s->revenue, 2),
        ];
    }

    /** صف لكل (دولة، ولاية، منهج) بأعداده — كل التجميعات الأخرى تُشتق منه */
    private function groupedRows(array $filters): Collection
    {
        return $this->filtered($filters)
            ->toBase()
            ->leftJoinSub($this->bookingStats(), 'bs', 'bs.student_id', '=', 'students.id')
            ->select([
                'students.country_id',
                'students.region_id',
                'students.curriculum_id',
                DB::raw('COUNT(*) as students'),
                DB::raw('COALESCE(SUM(bs.paying), 0) as paying'),
                DB::raw('COALESCE(SUM(bs.running), 0) as running'),
                DB::raw('COALESCE(SUM(bs.revenue), 0) as revenue'),
            ])
            ->groupBy('students.country_id', 'students.region_id', 'students.curriculum_id')
            ->get();
    }

    private function bookingStats()
    {
        $paid = "'".implode("','", self::PAID_STATUSES)."'";
        $running = "'".implode("','", self::RUNNING_STATUSES)."'";

        return DB::table('bookings')
            ->select('student_id')
            ->selectRaw("MAX(CASE WHEN status IN ({$paid}) AND amount_paid > 0 THEN 1 ELSE 0 END) as paying")
            ->selectRaw("MAX(CASE WHEN status IN ({$running}) THEN 1 ELSE 0 END) as running")
            ->selectRaw("SUM(CASE WHEN status IN ({$paid}) THEN amount_paid ELSE 0 END) as revenue")
            ->groupBy('student_id');
    }

    private function filtered(array $filters): Builder
    {
        $dayStart = fn (string $date) => Carbon::parse($date, StudentsReportService::REPORT_TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'));

        return Student::query()
            ->when($filters['country_id'] ?? null, fn ($q, $id) => $q->where('students.country_id', $id))
            ->when($filters['region_id'] ?? null, fn ($q, $id) => $q->where('students.region_id', $id))
            ->when($filters['curriculum_id'] ?? null, fn ($q, $id) => $q->where('students.curriculum_id', $id))
            ->when($filters['education_type'] ?? null, fn ($q, $type) => $q->where('students.education_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('students.created_at', '>=', $dayStart($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('students.created_at', '<', $dayStart($to)->addDay()));
    }

    /** مصفوفة الموقع × المنهج: صف لكل موقع، وعمود لكل منهج له طلاب ضمن الفلاتر */
    private function matrix(Collection $groups, Collection $byLocation, Collection $byCurriculum): array
    {
        $columns = $byCurriculum->map(fn (array $c) => ['curriculumId' => $c['curriculumId'], 'curriculum' => $c['curriculum']])->values();

        $rows = $byLocation->map(function (array $location) use ($groups, $columns) {
            $cells = $groups->filter(fn ($g) => $g->country_id == $location['countryId'] && $g->region_id == $location['regionId']);

            return [
                'countryId' => $location['countryId'],
                'country' => $location['country'],
                'regionId' => $location['regionId'],
                'region' => $location['region'],
                'counts' => $columns->map(fn (array $col) => (int) $cells->where('curriculum_id', $col['curriculumId'])->sum('students'))->all(),
                'total' => $location['students'],
            ];
        });

        return ['columns' => $columns->all(), 'rows' => $rows->values()->all()];
    }

    private function sumBy(Collection $groups, callable $key): Collection
    {
        return $groups->groupBy($key)->map(fn (Collection $items) => $this->sum($items));
    }

    private function sum(Collection $items): array
    {
        $students = (int) $items->sum('students');
        $paying = (int) $items->sum('paying');

        return [
            'students' => $students,
            'paying' => $paying,
            'running' => (int) $items->sum('running'),
            'revenue' => round((float) $items->sum('revenue'), 2),
            'conversionRate' => $students ? round($paying / $students * 100, 1) : 0,
        ];
    }

    private function locationLabel(object $group, array $names): array
    {
        return [
            'countryId' => $group->country_id,
            'country' => $group->country_id ? $names['countries'][$group->country_id] ?? null : null,
            'regionId' => $group->region_id,
            'region' => $group->region_id ? $names['regions'][$group->region_id] ?? null : null,
        ];
    }

    private function names(): array
    {
        return [
            'countries' => Country::query()->pluck('name_ar', 'id')->all(),
            'regions' => Region::query()->pluck('name_ar', 'id')->all(),
            'curricula' => Curriculum::query()->pluck('name_ar', 'id')->all(),
        ];
    }
}
