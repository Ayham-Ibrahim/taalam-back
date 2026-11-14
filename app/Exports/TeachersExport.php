<?php

namespace App\Exports;

use App\Exports\Concerns\SelectableColumns;
use App\Exports\Sheets\ReportSheet;
use App\Models\Booking;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * قائمة المعلمين لدى الأدمن كملف Excel — بنفس فلاتر صفحة إدارة المعلمين (الحالة، النوع،
 * البحث) التي يبنيها TeacherController::filteredQuery، فيطابق الملف ما يراه الأدمن.
 * الأدمن يختار الأعمدة (columns[])؛ بلا اختيار تُصدَّر كلها.
 */
class TeachersExport implements WithMultipleSheets
{
    private const TYPE_LABELS = ['school' => 'مدرسي', 'university' => 'جامعي', 'training_center' => 'مركز تدريب'];

    private const STATUS_LABELS = [
        'created' => 'بانتظار المراجعة',
        'invited' => 'بانتظار المراجعة',
        'active_unverified' => 'بانتظار المراجعة',
        'pending_verification' => 'بانتظار المراجعة',
        'verified' => 'معتمد',
        'suspended' => 'موقوف',
        'rejected' => 'مرفوض',
    ];

    private const QUALIFICATION_LABELS = [
        'bachelor' => 'بكالوريوس',
        'master' => 'ماجستير',
        'phd' => 'دكتوراه',
        'professional_cert' => 'شهادة مهنية',
        'diploma' => 'دبلوم',
    ];

    /** حجوزات تُحسب طالباً للمعلم: المؤكد والجاري والمكتمل (تشمل الباقات المجانية، لا المعلّق ولا الملغى) */
    private const PAID_STATUSES = ['confirmed', 'active', 'completed'];

    private const EXPERIENCE_LABELS = ['under_1' => 'أقل من سنة', '1_3' => '1–3 سنوات', '3_5' => '3–5 سنوات', 'over_5' => 'أكثر من 5 سنوات'];

    /** علاقات أعمدة القوائم — تُحمَّل فقط إن اختير عمودها */
    private const RELATION_COLUMNS = ['subjects', 'curricula', 'majors', 'languages'];

    private readonly SelectableColumns $columns;

    public function __construct(private readonly Builder $query, ?array $columns = null)
    {
        $this->columns = self::columns($columns);
    }

    /** @return SelectableColumns<Teacher> */
    public static function columns(?array $selected = null): SelectableColumns
    {
        $names = fn ($items) => $items->pluck('name_ar')->join('، ') ?: '—';

        return new SelectableColumns([
            'name' => ['المعلم', fn (Teacher $t) => $t->user?->name],
            'email' => ['البريد', fn (Teacher $t) => $t->user?->email],
            'phone' => ['الهاتف', fn (Teacher $t) => $t->user?->phone],
            'type' => ['النوع', fn (Teacher $t) => self::TYPE_LABELS[$t->teacher_type] ?? $t->teacher_type],
            'status' => ['الحالة', fn (Teacher $t) => self::STATUS_LABELS[$t->status] ?? $t->status],
            'city' => ['المدينة', fn (Teacher $t) => $t->city ?? '—'],
            'qualification' => ['المؤهل', fn (Teacher $t) => self::QUALIFICATION_LABELS[$t->qualification] ?? '—'],
            'experience' => ['سنوات الخبرة', fn (Teacher $t) => self::EXPERIENCE_LABELS[$t->experience_years] ?? '—'],
            'subjects' => ['المواد', fn (Teacher $t) => $names($t->subjects)],
            'curricula' => ['المناهج', fn (Teacher $t) => $names($t->curricula)],
            'majors' => ['التخصصات', fn (Teacher $t) => $names($t->majors)],
            'languages' => ['اللغات', fn (Teacher $t) => $names($t->languages)],
            'rating' => ['متوسط التقييم', fn (Teacher $t) => (float) $t->rating_avg],
            'reviews_count' => ['عدد التقييمات', fn (Teacher $t) => (int) $t->reviews_count],
            'students_count' => ['عدد الطلاب', fn (Teacher $t) => (int) $t->paid_students_count],
            'registered_at' => ['تاريخ التسجيل', fn (Teacher $t) => $t->created_at?->toDateString()],
            'verified_at' => ['تاريخ الاعتماد', fn (Teacher $t) => $t->verified_at?->toDateString() ?? '—'],
        ], $selected);
    }

    public function sheets(): array
    {
        // نسخة لكل استدعاء — Laravel Excel يستدعي sheets() أكثر من مرة، وتعديل الأصل يكرر الـ join
        $query = (clone $this->query)
            ->select('teachers.*')
            ->with(['user:id,name,email,phone'])
            ->with(array_map(
                fn (string $relation) => "{$relation}:id,name_ar",
                array_values(array_filter(self::RELATION_COLUMNS, fn (string $c) => $this->columns->wants($c))),
            ));

        // عدد الطلاب يُحسب من الحجوزات لا من teachers.students_count — ذلك العمود لا يحدّثه أي كود
        if ($this->columns->wants('students_count')) {
            $query->addSelect(['paid_students_count' => Booking::query()
                ->selectRaw('COUNT(DISTINCT student_id)')
                ->whereColumn('bookings.teacher_id', 'teachers.id')
                ->whereIn('status', self::PAID_STATUSES),
            ]);
        }

        $teachers = $query->get();

        return [new ReportSheet(
            'المعلمون',
            $this->columns->headings(),
            $teachers->map(fn (Teacher $t) => $this->columns->row($t))->all(),
            $this->columns->totalsRow("الإجمالي: {$teachers->count()} معلم"),
        )];
    }
}
