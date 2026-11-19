<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Curriculum;
use App\Models\Region;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تقرير التسويق: توزيع الطلاب حسب الموقع (دولة ← ولاية) × المنهج، وحسب الصف والمادة — لتوجيه
 * الحملات. لكل مجموعة: عدد الطلاب، من دفع فعلاً (حجز مدفوع بمبلغ > 0 — المجانية لا تُحسب)،
 * من يدرس حالياً (حجز جارٍ confirmed/active مدفوع بمبلغ > 0 — فلا يتجاوز عدد من دفع)، والإيراد
 * (amount_paid للحجوزات المدفوعة).
 *
 * مواد الطالب: مواد باقاته المحجوزة بحالة مدفوعة (PAID_STATUSES) — ما يدرسه فعلاً. الطالب الواحد
 * قد يُعد في أكثر من مادة، فمجموع "حسب المادة" لا يساوي عدد الطلاب، وإيراد المادة إيراد باقاتها فقط.
 *
 * الفلاتر (كلها اختيارية): الدولة، الولاية، المنهج، نوع التعليم، الصف، المادة، وفترة التسجيل
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

        // بالترتيب الدراسي، ومن لم يحدد صفه أخيراً
        $byGrade = $this->sumBy($groups, fn ($g) => (string) $g->grade)
            ->map(fn (array $sum, string $key) => ['grade' => $key === '' ? null : (int) $key] + $sum)
            ->sortBy(fn (array $row) => $row['grade'] ?? PHP_INT_MAX)
            ->values();

        $bySubject = collect($this->bySubject($filters));
        $cells = $this->cellsByLocation($groups);
        $cellCount = fn (string $column) => fn (array $location, array $col) => (int) $cells
            ->get(self::locationKey($location), collect())
            ->filter(fn ($g) => $g->{$column} === null ? $col['value'] === null : (int) $g->{$column} === $col['value'])
            ->sum('students');

        return [
            'totals' => $this->sum($groups) + [
                'withoutLocation' => (int) $groups->whereNull('country_id')->sum('students'),
            ],
            'byLocation' => $byLocation->all(),
            'byCurriculum' => $byCurriculum->all(),
            'byGrade' => $byGrade->all(),
            'bySubject' => $bySubject->all(),
            'matrix' => $this->matrix(
                $byLocation,
                $byCurriculum->map(fn (array $c) => ['curriculumId' => $c['curriculumId'], 'curriculum' => $c['curriculum'], 'value' => $c['curriculumId']]),
                $cellCount('curriculum_id'),
            ),
            'gradeMatrix' => $this->matrix(
                $byLocation,
                $byGrade->map(fn (array $g) => ['grade' => $g['grade'], 'value' => $g['grade']]),
                $cellCount('grade'),
            ),
            'subjectMatrix' => $this->subjectMatrix($filters, $byLocation, $bySubject),
        ];
    }

    /** قائمة الطلاب ضمن نفس الفلاتر — لتنزيل/مراجعة أفراد شريحة معيّنة */
    public function students(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->studentsQuery($filters)
            ->orderByDesc('students.id')
            ->paginate($perPage);

        $subjects = $this->subjectsByStudent($page->getCollection()->pluck('id'));

        return $page->through(fn (Student $s) => $this->studentRow($s, $subjects));
    }

    /** كل الطلاب ضمن الفلاتر بلا صفحات — للتصدير */
    public function allStudents(array $filters): array
    {
        $students = $this->studentsQuery($filters)
            ->orderByDesc('students.id')
            ->get();

        $subjects = $this->subjectsByStudent($students->pluck('id'));

        return $students->map(fn (Student $s) => $this->studentRow($s, $subjects))->all();
    }

    /**
     * صف لكل مادة يدرسها طلاب الفلاتر (من باقاتهم المدفوعة). الطالب يُعد مرة في كل مادة له؛
     * الإيراد مجموع ما دُفع لباقات تلك المادة وحدها. بفلتر مادة يقتصر على تلك المادة.
     */
    private function bySubject(array $filters): array
    {
        $running = "'".implode("','", self::RUNNING_STATUSES)."'";
        $names = Subject::query()->pluck('name_ar', 'id');

        return $this->filtered($filters)
            ->toBase()
            ->join('bookings as sb', 'sb.student_id', '=', 'students.id')
            ->join('packages as sp', 'sp.id', '=', 'sb.package_id')
            ->whereIn('sb.status', self::PAID_STATUSES)
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('sp.subject_id', $id))
            ->select('sp.subject_id')
            ->selectRaw('COUNT(DISTINCT students.id) as students')
            ->selectRaw('COUNT(DISTINCT CASE WHEN sb.amount_paid > 0 THEN students.id END) as paying')
            ->selectRaw("COUNT(DISTINCT CASE WHEN sb.status IN ({$running}) AND sb.amount_paid > 0 THEN students.id END) as running")
            ->selectRaw('SUM(sb.amount_paid) as revenue')
            ->groupBy('sp.subject_id')
            ->get()
            ->map(fn ($row) => [
                'subjectId' => (int) $row->subject_id,
                'subject' => $names[$row->subject_id] ?? null,
                'students' => (int) $row->students,
                'paying' => (int) $row->paying,
                'running' => (int) $row->running,
                'revenue' => round((float) $row->revenue, 2),
                'conversionRate' => $row->students ? round($row->paying / $row->students * 100, 1) : 0,
            ])
            ->sortByDesc('students')
            ->values()
            ->all();
    }

    /**
     * أسماء مواد الباقات المدفوعة لكل طالب — استعلام واحد لصفحة القائمة/التصدير كلها.
     *
     * @return Collection<int, array<int, string>> [student_id => أسماء المواد]
     */
    private function subjectsByStudent(Collection $studentIds): Collection
    {
        if ($studentIds->isEmpty()) {
            return collect();
        }

        return DB::table('bookings')
            ->join('packages', 'packages.id', '=', 'bookings.package_id')
            ->join('subjects', 'subjects.id', '=', 'packages.subject_id')
            ->whereIn('bookings.student_id', $studentIds)
            ->whereIn('bookings.status', self::PAID_STATUSES)
            ->select('bookings.student_id', 'subjects.name_ar')
            ->distinct()
            ->orderBy('subjects.name_ar')
            ->get()
            ->groupBy('student_id')
            ->map(fn (Collection $rows) => $rows->pluck('name_ar')->all());
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

    private function studentRow(Student $s, Collection $subjects): array
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
            'subjects' => $subjects->get($s->id, []),
            'registeredAt' => $s->created_at?->copy()->setTimezone(StudentsReportService::REPORT_TIMEZONE)->toDateString(),
            'isPaying' => (bool) $s->paying,
            'hasRunningPackage' => (bool) $s->running,
            'revenue' => round((float) $s->revenue, 2),
        ];
    }

    /** صف لكل (دولة، ولاية، منهج، صف) بأعداده — كل التجميعات الأخرى عدا المادة تُشتق منه */
    private function groupedRows(array $filters): Collection
    {
        return $this->filtered($filters)
            ->toBase()
            ->leftJoinSub($this->bookingStats(), 'bs', 'bs.student_id', '=', 'students.id')
            ->select([
                'students.country_id',
                'students.region_id',
                'students.curriculum_id',
                'students.grade',
                DB::raw('COUNT(*) as students'),
                DB::raw('COALESCE(SUM(bs.paying), 0) as paying'),
                DB::raw('COALESCE(SUM(bs.running), 0) as running'),
                DB::raw('COALESCE(SUM(bs.revenue), 0) as revenue'),
            ])
            ->groupBy('students.country_id', 'students.region_id', 'students.curriculum_id', 'students.grade')
            ->get();
    }

    private function bookingStats()
    {
        $paid = "'".implode("','", self::PAID_STATUSES)."'";
        $running = "'".implode("','", self::RUNNING_STATUSES)."'";

        return DB::table('bookings')
            ->select('student_id')
            ->selectRaw("MAX(CASE WHEN status IN ({$paid}) AND amount_paid > 0 THEN 1 ELSE 0 END) as paying")
            ->selectRaw("MAX(CASE WHEN status IN ({$running}) AND amount_paid > 0 THEN 1 ELSE 0 END) as running")
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
            ->when($filters['grade'] ?? null, fn ($q, $grade) => $q->where('students.grade', $grade))
            // من يدرس المادة: له باقة مدفوعة منها — نفس تعريف مواد الطالب في bySubject/subjectsByStudent
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->whereExists(fn ($e) => $e
                ->from('bookings')
                ->join('packages', 'packages.id', '=', 'bookings.package_id')
                ->whereColumn('bookings.student_id', 'students.id')
                ->whereIn('bookings.status', self::PAID_STATUSES)
                ->where('packages.subject_id', $id)))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('students.created_at', '>=', $dayStart($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('students.created_at', '<', $dayStart($to)->addDay()));
    }

    /**
     * مصفوفة الموقع × (منهج/صف/مادة): صف لكل موقع، وعمود لكل قيمة لها طلاب ضمن الفلاتر.
     * $count يعطي عدد طلاب الخلية من [موقع، عمود]؛ total عدد طلاب الموقع كله.
     */
    private function matrix(Collection $byLocation, Collection $columns, callable $count): array
    {
        $rows = $byLocation->map(fn (array $location) => [
            'countryId' => $location['countryId'],
            'country' => $location['country'],
            'regionId' => $location['regionId'],
            'region' => $location['region'],
            'counts' => $columns->map(fn (array $col) => $count($location, $col))->all(),
            'total' => $location['students'],
        ]);

        return ['columns' => $columns->values()->all(), 'rows' => $rows->values()->all()];
    }

    /** مصفوفات المنهج والصف تُشتق من groupedRows — صف لكل موقع بخلاياه */
    private function cellsByLocation(Collection $groups): Collection
    {
        return $groups->groupBy(fn ($g) => $g->country_id.':'.$g->region_id);
    }

    private static function locationKey(array $location): string
    {
        return $location['countryId'].':'.$location['regionId'];
    }

    /**
     * الموقع × المادة: طلاب كل موقع في كل مادة من باقاتهم المدفوعة — الطالب يُعد في كل مادة له،
     * فمجموع خلايا الصف قد يتجاوز total أو ينقص عنه (من بلا باقة مدفوعة لا مادة له).
     */
    private function subjectMatrix(array $filters, Collection $byLocation, Collection $bySubject): array
    {
        $cells = $this->filtered($filters)
            ->toBase()
            ->join('bookings as mb', 'mb.student_id', '=', 'students.id')
            ->join('packages as mp', 'mp.id', '=', 'mb.package_id')
            ->whereIn('mb.status', self::PAID_STATUSES)
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('mp.subject_id', $id))
            ->select('students.country_id', 'students.region_id', 'mp.subject_id')
            ->selectRaw('COUNT(DISTINCT students.id) as students')
            ->groupBy('students.country_id', 'students.region_id', 'mp.subject_id')
            ->get()
            ->keyBy(fn ($c) => $c->country_id.':'.$c->region_id.':'.$c->subject_id);

        return $this->matrix(
            $byLocation,
            $bySubject->map(fn (array $s) => ['subjectId' => $s['subjectId'], 'subject' => $s['subject']]),
            fn (array $location, array $col) => (int) ($cells->get(self::locationKey($location).':'.$col['subjectId'])?->students ?? 0),
        );
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
