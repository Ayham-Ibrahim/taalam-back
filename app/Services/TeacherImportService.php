<?php

namespace App\Services;

use App\Imports\TeachersImport;
use App\Models\ImportBatch;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * استيراد جماعي للمعلمين من Excel/CSV. فشل صف واحد لا يوقف الدفعة — كل صف
 * مستقل بمعاملته الخاصة، والأخطاء تُجمَع وتُعاد للأدمن مع رقم الصف.
 * يوازي App\Services\StudentImportService تماماً — يعيد استخدام
 * TeacherService::invite() نفسه المستخدَم في الدعوة الفردية (User + Teacher
 * بحالة "invited" + AccountInvitation + إشعار TeacherInvited + سجل تدقيق)،
 * بدل تكرار تلك الخطوات هنا.
 *
 * يعمل داخل ProcessTeacherImportJob (طابور) لا طلب HTTP مباشر — راجع تعليق
 * StudentImportService لتفصيل السبب؛ $batch هو مصدر الحقيقة الوحيد للتقدّم.
 */
class TeacherImportService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TeacherService $teacherService,
        private readonly NotificationService $notifications,
    ) {}

    public function processBatch(ImportBatch $batch): void
    {
        $sheets = Excel::toCollection(new TeachersImport, $batch->file_path, 'local');
        $rows = $sheets->first() ?? collect();

        $maxRows = (int) $this->settings->get('teacher_import_max_rows', 500);

        if ($rows->count() > $maxRows) {
            $batch->update([
                'status' => 'failed',
                'total_rows' => $rows->count(),
                'failure_reason' => "عدد الصفوف ({$rows->count()}) يتجاوز الحد الأقصى المسموح ({$maxRows} صف)",
                'completed_at' => now(),
            ]);

            return;
        }

        $batch->update(['status' => 'processing', 'total_rows' => $rows->count(), 'started_at' => now()]);

        $admin = $batch->creator;
        $imported = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 للفهرسة من صفر، +1 لصف العناوين
            $rowArray = $row->toArray();

            try {
                $data = $this->validateRow($rowArray);
                $teacher = $this->teacherService->invite($data, $admin, $this->notifications->bulkDelaySeconds($imported));
                $this->applyExtraFields($teacher, $data);
                $imported++;
            } catch (ValidationException $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => $e->errors(),
                ];
            }

            if (($index + 1) % 25 === 0) {
                $batch->update(['processed_rows' => $index + 1]);
            }
        }

        $batch->update([
            'status' => 'completed',
            'processed_rows' => $rows->count(),
            'imported_count' => $imported,
            'failed_count' => count($errors),
            'errors' => $errors,
            'completed_at' => now(),
        ]);
    }

    /**
     * تدعم شكلين معاً: القالب البسيط الأصلي (name/email/phone/teacher_type
     * حرفياً — يطابقه اختبار الاستيراد والقالب القابل للتنزيل)، وملفات نماذج
     * خارجية حقيقية (Google Forms) بعناوين أعمدة أطول وأثقل (Full Name
     * (Arabic)، Type Of Teaching...) — كل حقل منطقي يُقرأ من أول مفتاح متوفر
     * من الاثنين، فلا ينكسر أي منهما بإضافة الآخر.
     */
    private function validateRow(array $row): array
    {
        $name = $this->normalizeValue($row['name'] ?? null)
            ?? $this->normalizeValue($row['full_name_arabic'] ?? null)
            ?? $this->normalizeValue($row['full_name_english'] ?? null);

        $normalized = [
            'name' => $name,
            'email' => $this->normalizeValue($row['email'] ?? $row['email_address'] ?? null),
            'phone' => $this->normalizeValue($row['phone'] ?? $row['phone_number'] ?? null),
            'whatsapp' => $this->normalizeValue($row['whatsapp'] ?? $row['whatsapp_number'] ?? null),
            'gender' => $this->mapGender($this->normalizeValue($row['gender'] ?? null)),
            'teacher_type' => $this->mapTeacherType(
                $this->normalizeValue($row['teacher_type'] ?? $row['type_of_teaching'] ?? null)
            ),
        ];

        $validator = Validator::make($normalized, [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'unique:users,phone'],
            'whatsapp' => ['nullable', 'string', 'max:25'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'teacher_type' => ['required', Rule::in(['school', 'university', 'training_center'])],
        ], [
            'teacher_type.required' => 'نوع المعلم (مدرسي/جامعي/مركز تدريب) مفقود أو غير معروف من قيمة العمود',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();
        $data['bio'] = $this->buildBioFromExtraFields($row);

        return $data;
    }

    /**
     * whatsapp/gender/bio ليست جزءاً من TeacherService::invite() المشتركة مع
     * مسار الدعوة الفردية (Add Teacher) — تُضبط هنا مباشرة بعد الإنشاء بدل
     * تعديل تلك الدالة المشتركة، فلا يتأثر مسار الدعوة الفردية إطلاقاً بهذا.
     */
    private function applyExtraFields(Teacher $teacher, array $data): void
    {
        if (! empty($data['whatsapp']) || ! empty($data['gender'])) {
            User::where('id', $teacher->user_id)->update(array_filter([
                'whatsapp' => $data['whatsapp'] ?? null,
                'gender' => $data['gender'] ?? null,
            ]));
        }

        if (! empty($data['bio'])) {
            $teacher->update(['bio' => $data['bio']]);
        }
    }

    private function mapGender(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $lower = mb_strtolower($raw);

        return match (true) {
            str_starts_with($lower, 'f') => 'female',
            str_starts_with($lower, 'm') => 'male',
            default => null,
        };
    }

    /**
     * يقبل القيمة النظيفة الجاهزة (school/university/training_center) وأيضاً
     * نصاً حراً من نموذج خارجي ("School Teacher ( K - 12 )"، "Training
     * courses"...) — تطابق جزئي حسب كلمة مفتاحية بدل تطابق حرفي صارم.
     */
    private function mapTeacherType(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $lower = mb_strtolower($raw);

        return match (true) {
            str_contains($lower, 'university') => 'university',
            str_contains($lower, 'training') => 'training_center',
            str_contains($lower, 'school') => 'school',
            default => null,
        };
    }

    /**
     * حقول لا تملك عموداً مخصَّصاً في جدول teachers (الجنسية، الإقامة، رقم
     * الهوية، الجامعة، التخصص، المواد، المنهج بنص حر...) — تُجمَع في النبذة
     * كنص خام بدل فقدانها، والمعلم يُكمل ملفه الفعلي (مواد/مناهج/مؤهل منظَّم)
     * بنفسه لاحقاً عبر شاشة إكمال الملف الشخصي كالمعتاد. اسم المواد/المنهج في
     * نماذج خارجية نص حر غير مطابق لقوائم النظام (subjects/curricula)، فمطابقتها
     * تلقائياً محفوف بأخطاء عبر مئات الصفوف المختلفة الصياغة.
     */
    private function buildBioFromExtraFields(array $row): ?string
    {
        $labels = [
            'nationality' => 'الجنسية',
            'current_residence' => 'مكان الإقامة',
            'national_id_number' => 'رقم الهوية',
            'passport_number_if_available' => 'رقم الجواز',
            'highest_educational_qualification' => 'المؤهل العلمي',
            'academic_dagree' => 'الدرجة الأكاديمية',
            'university_inatitution' => 'الجامعة/المؤسسة',
            'major' => 'التخصص',
            'graduation_year' => 'سنة التخرج',
            'subjects_you_teach' => 'المواد التي يدرّسها',
            'years_experience' => 'سنوات الخبرة',
            'curriculum_type' => 'نوع المنهج',
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            $value = $this->normalizeValue($row[$key] ?? null);
            if ($value !== null) {
                $lines[] = "{$label}: {$value}";
            }
        }

        if (empty($lines)) {
            return null;
        }

        // نفس حد 500 حرف المفروض على bio عبر UpdateTeacherProfileRequest — تخزين
        // أكثر هنا يبدو مقبولاً للوهلة الأولى لكنه يمنع المعلم لاحقاً من حفظ أي
        // تعديل على ملفه الشخصي (الطلب سيُرفَض بحجة تجاوز الحد) قبل أن يُقصّره هو نفسه.
        return mb_substr(implode("\n", $lines), 0, 500);
    }

    /**
     * خلايا Excel الفارغة تصل غالباً كسلسلة فارغة "" لا null فعلي — تُعامَل هنا
     * كـ null موحَّد، وإلا فقد ترفض قاعدة unique صفاً بهاتف فارغ بحجة تكراره
     * مع صف آخر بهاتف فارغ أيضاً. يوازي StudentImportService::normalizeValue.
     */
    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
