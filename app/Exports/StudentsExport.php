<?php

namespace App\Exports;

use App\Exports\Concerns\SelectableColumns;
use App\Exports\Sheets\ReportSheet;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentsReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * قائمة الطلاب لدى الأدمن كملف Excel — بنفس فلاتر صفحة إدارة الطلاب (البحث، نوع التعليم،
 * الدولة، الولاية) التي يبنيها StudentController::filteredQuery. الأدمن يختار الأعمدة
 * (columns[])؛ بلا اختيار تُصدَّر كلها.
 */
class StudentsExport implements WithMultipleSheets
{
    private const EDUCATION_TYPE_LABELS = ['school' => 'مدرسي', 'university' => 'جامعي', 'training' => 'تدريبي'];

    private const ACADEMIC_LEVEL_LABELS = ['diploma' => 'دبلوم', 'bachelor' => 'بكالوريوس', 'master' => 'ماجستير'];

    private const TRAINING_LEVEL_LABELS = ['beginner' => 'مبتدئ', 'intermediate' => 'متوسط', 'advanced' => 'متقدم'];

    private const GENDER_LABELS = ['male' => 'ذكر', 'female' => 'أنثى'];

    private const REGISTRATION_SOURCE_LABELS = ['self' => 'تسجيل ذاتي', 'admin' => 'الإدارة', 'import' => 'استيراد', 'sibling' => 'أخ'];

    private const ACCOUNT_TYPE_LABELS = [User::ACCOUNT_REGULAR => 'عادي', User::ACCOUNT_CAMPAIGN => 'حملة', User::ACCOUNT_GUEST => 'زائر'];

    /** نفس تعريفات تقرير التسويق: المدفوع والجاري */
    private const PAID_STATUSES = ['confirmed', 'active', 'completed'];

    private const RUNNING_STATUSES = ['confirmed', 'active'];

    /** عمود ⇐ العلاقة التي يحتاجها — تُحمَّل فقط إن اختير العمود */
    private const RELATION_COLUMNS = [
        'country' => 'locationCountry:id,name_ar',
        'region' => 'region:id,name_ar',
        'curriculum' => 'curriculum:id,name_ar',
        'stage' => 'stage:id,name_ar',
        'university' => 'university:id,name_ar',
        'major' => 'major:id,name_ar',
        'course_field' => 'courseField:id,name_ar',
        'registered_by' => 'creator:id,name,role',
    ];

    /** أعمدة تحتاج إحصاءات الحجوزات */
    private const BOOKING_COLUMNS = ['bookings_count', 'is_paying', 'has_running', 'total_paid'];

    private readonly SelectableColumns $columns;

    public function __construct(private readonly Builder $query, ?array $columns = null)
    {
        $this->columns = self::columns($columns);
    }

    /** @return SelectableColumns<Student> */
    public static function columns(?array $selected = null): SelectableColumns
    {
        $label = fn (array $labels, ?string $value) => $value ? $labels[$value] ?? $value : '—';
        $yesNo = fn ($value) => $value ? 'نعم' : 'لا';

        return new SelectableColumns([
            'name' => ['الطالب', fn (Student $s) => $s->user?->name],
            'email' => ['البريد', fn (Student $s) => $s->user?->email ?? '—'],
            'phone' => ['الهاتف', fn (Student $s) => $s->user?->phone ?? '—'],
            'whatsapp' => ['واتساب', fn (Student $s) => $s->user?->whatsapp ?? '—'],
            'gender' => ['الجنس', fn (Student $s) => $label(self::GENDER_LABELS, $s->user?->gender)],
            'birth_date' => ['تاريخ الميلاد', fn (Student $s) => $s->birth_date?->toDateString() ?? '—'],
            'guardian_name' => ['اسم ولي الأمر', fn (Student $s) => $s->guardian_name ?? '—'],
            'guardian_phone' => ['هاتف ولي الأمر', fn (Student $s) => $s->guardian_phone ?? '—'],
            'country' => ['الدولة', fn (Student $s) => $s->locationCountry?->name_ar ?? '—'],
            'region' => ['الولاية', fn (Student $s) => $s->region?->name_ar ?? '—'],
            'education_type' => ['نوع التعليم', fn (Student $s) => $s->education_type ? self::EDUCATION_TYPE_LABELS[$s->education_type] : 'غير مكتمل'],
            'curriculum' => ['المنهج', fn (Student $s) => $s->curriculum?->name_ar ?? '—'],
            'stage' => ['المرحلة', fn (Student $s) => $s->stage?->name_ar ?? '—'],
            'grade' => ['الصف', fn (Student $s) => $s->grade ?? '—'],
            'university' => ['الجامعة', fn (Student $s) => $s->university?->name_ar ?? '—'],
            'major' => ['التخصص', fn (Student $s) => $s->major?->name_ar ?? '—'],
            'academic_level' => ['المستوى الجامعي', fn (Student $s) => $label(self::ACADEMIC_LEVEL_LABELS, $s->academic_level)],
            'course_field' => ['مجال الدورة', fn (Student $s) => $s->courseField?->name_ar ?? '—'],
            'training_level' => ['مستوى التدريب', fn (Student $s) => $label(self::TRAINING_LEVEL_LABELS, $s->level)],
            'account_type' => ['نوع الحساب', fn (Student $s) => $label(self::ACCOUNT_TYPE_LABELS, $s->user?->account_type)],
            'is_active' => ['الحساب نشط؟', fn (Student $s) => $yesNo($s->user?->is_active)],
            'imported' => ['مستورد؟', fn (Student $s) => $yesNo($s->imported)],
            'registered_by' => ['سُجِّل بواسطة', fn (Student $s) => self::REGISTRATION_SOURCE_LABELS[$s->registrationSource()]
                .($s->creator ? ' — '.$s->creator->name : '')],
            'bookings_count' => ['عدد الباقات', fn (Student $s) => (int) $s->paid_bookings_count],
            'is_paying' => ['هل دفع؟', fn (Student $s) => $yesNo($s->is_paying)],
            'has_running' => ['يدرس حالياً؟', fn (Student $s) => $yesNo($s->has_running)],
            'total_paid' => ['إجمالي ما دفعه (AED)', fn (Student $s) => round((float) $s->total_paid, 2)],
            'registered_at' => ['تاريخ التسجيل', fn (Student $s) => $s->created_at?->copy()->setTimezone(StudentsReportService::REPORT_TIMEZONE)->toDateString()],
        ], $selected);
    }

    public function sheets(): array
    {
        $relations = array_values(array_filter(
            self::RELATION_COLUMNS,
            fn (string $column) => $this->columns->wants($column),
            ARRAY_FILTER_USE_KEY,
        ));

        // نسخة لكل استدعاء — Laravel Excel يستدعي sheets() أكثر من مرة، وتعديل الأصل يكرر الـ join
        $query = (clone $this->query)
            ->select('students.*')
            ->with(['user:id,name,email,phone,whatsapp,gender,account_type,is_active', ...$relations]);

        if (array_filter(self::BOOKING_COLUMNS, fn (string $c) => $this->columns->wants($c))) {
            $query->leftJoinSub($this->bookingStats(), 'bs', 'bs.student_id', '=', 'students.id')
                ->addSelect('bs.paid_bookings_count', 'bs.is_paying', 'bs.has_running', 'bs.total_paid');
        }

        $students = $query->get();

        return [new ReportSheet(
            'الطلاب',
            $this->columns->headings(),
            $students->map(fn (Student $s) => $this->columns->row($s))->all(),
            $this->columns->totalsRow("الإجمالي: {$students->count()} طالب"),
        )];
    }

    /** لكل طالب: عدد باقاته المدفوعة/الجارية، هل دفع مبلغاً، هل يدرس الآن، وإجمالي ما دفعه */
    private function bookingStats()
    {
        $paid = "'".implode("','", self::PAID_STATUSES)."'";
        $running = "'".implode("','", self::RUNNING_STATUSES)."'";

        return DB::table('bookings')
            ->select('student_id')
            ->selectRaw("SUM(CASE WHEN status IN ({$paid}) THEN 1 ELSE 0 END) as paid_bookings_count")
            ->selectRaw("MAX(CASE WHEN status IN ({$paid}) AND amount_paid > 0 THEN 1 ELSE 0 END) as is_paying")
            ->selectRaw("MAX(CASE WHEN status IN ({$running}) THEN 1 ELSE 0 END) as has_running")
            ->selectRaw("SUM(CASE WHEN status IN ({$paid}) THEN amount_paid ELSE 0 END) as total_paid")
            ->groupBy('student_id');
    }
}
