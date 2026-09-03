<?php

namespace App\Services;

use App\Imports\StudentsImport;
use App\Models\AccountInvitation;
use App\Models\CourseField;
use App\Models\Curriculum;
use App\Models\ImportBatch;
use App\Models\Major;
use App\Models\Stage;
use App\Models\Student;
use App\Models\University;
use App\Models\User;
use App\Notifications\StudentImported;
use App\Rules\EmailHasMailExchangeRecord;
use App\Rules\NotSpreadsheetFormula;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * استيراد جماعي للطلاب من Excel/CSV. فشل صف واحد لا يوقف الدفعة — كل صف
 * مستقل بمعاملته الخاصة، والأخطاء تُجمَع وتُعاد للأدمن مع رقم الصف.
 *
 * يعمل الآن داخل ProcessStudentImportJob (طابور) لا طلب HTTP مباشر — ملفات
 * بآلاف الصفوف كانت تتجاوز مهلة المتصفح رغم اكتمالها فعلياً في الخلفية.
 * $batch (ImportBatch) هو مصدر الحقيقة الوحيد لتقدّم/نتيجة العملية الآن،
 * يتابعه الأدمن عبر GET /import-batches بدل انتظار استجابة الطلب الأصلي.
 */
class StudentImportService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    public function processBatch(ImportBatch $batch): void
    {
        $sheets = Excel::toCollection(new StudentsImport, $batch->file_path, 'local');
        $rows = $sheets->first() ?? collect();

        $maxRows = (int) $this->settings->get('student_import_max_rows', 500);

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

            try {
                $this->importRow($row->toArray(), $admin, $imported);
                $imported++;
            } catch (ValidationException $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => $e->errors(),
                ];
            }

            // كل 25 صفاً لا كل صف — تحديث الصف الواحد على دفعة آلاف الصفوف
            // يُبطئ الاستيراد فعلياً بلا فائدة تُذكَر لشريط تقدّم يُعرَض للأدمن.
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

    private function importRow(array $row, User $admin, int $notificationIndex): void
    {
        $data = $this->validateRow($row);

        DB::transaction(function () use ($data, $admin, $notificationIndex) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'student',
                'password' => null,
            ]);

            Student::create([
                'user_id' => $user->id,
                // اختياري بالكامل الآن — يبقى NULL حتى يُكمل الطالب ملفه بنفسه
                // (نفس آلية حساب ينشئه الأدمن مباشرة)، راجع validateRow().
                'education_type' => $data['education_type'] ?? null,
                'curriculum_id' => $data['curriculum_id'] ?? null,
                'stage_id' => $data['stage_id'] ?? null,
                'grade' => $data['grade'] ?? null,
                'university_id' => $data['university_id'] ?? null,
                'major_id' => $data['major_id'] ?? null,
                'academic_level' => $data['academic_level'] ?? null,
                'course_field_id' => $data['course_field_id'] ?? null,
                'level' => $data['level'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? null,
                'guardian_name' => $data['guardian_name'] ?? null,
                'guardian_phone' => $data['guardian_phone'] ?? null,
                'imported' => true,
                'imported_by' => $admin->id,
            ]);

            $invitation = AccountInvitation::create([
                'user_id' => $user->id,
                'invited_by' => $admin->id,
                'token' => Str::random(60),
                'expires_at' => now()->addHours((int) $this->settings->get('invitation_link_expiry_hours', 48)),
            ]);

            $this->notifications->send(
                $user,
                new StudentImported($invitation),
                'student.imported',
                $this->notifications->bulkDelaySeconds($notificationIndex),
            );
        });
    }

    /**
     * الاسم والإيميل فقط إلزاميان — أي صف يفتقدهما يُرفَض (ويُجمَع في errors[]
     * دون إيقاف بقية الدفعة، راجع import()). كل شيء آخر (الهاتف، المدينة،
     * البلد، التصنيف الأكاديمي...) اختياري بالكامل: يُستخدم إن وُجد وكان
     * صحيحاً، ويُترَك فارغاً بهدوء غير ذلك — الملفات الحقيقية الواردة من
     * جهات خارجية (قوائم شركاء) غالباً لا تحمل غير الاسم والإيميل والهاتف.
     */
    private function validateRow(array $row): array
    {
        $normalized = [
            'name' => $this->normalizeValue($row['name'] ?? null),
            'email' => $this->normalizeValue($row['email'] ?? null),
            // "phone1" هو اسم العمود الفعلي في ملفات استيراد شائعة (تصدير
            // أنظمة CRM خارجية) — نقبله كبديل عن "phone" كي لا يتعطل الاستيراد
            // بسبب تسمية عمود فقط.
            'phone' => $this->normalizeValue($row['phone'] ?? $row['phone1'] ?? null),
            'city' => $this->normalizeValue($row['city'] ?? null),
            'country' => $this->normalizeValue($row['country'] ?? null),
            'education_type' => $this->normalizeValue($row['education_type'] ?? null),
            'curriculum_code' => $this->normalizeValue($row['curriculum_code'] ?? null),
            'stage_code' => $this->normalizeValue($row['stage_code'] ?? null),
            'grade' => $this->normalizeValue($row['grade'] ?? null),
            'university_name' => $this->normalizeValue($row['university_name'] ?? null),
            'major_name' => $this->normalizeValue($row['major_name'] ?? null),
            'academic_level' => $this->normalizeValue($row['academic_level'] ?? null),
            'course_field_code' => $this->normalizeValue($row['course_field_code'] ?? null),
            'level' => $this->normalizeValue($row['level'] ?? null),
            'birth_date' => $this->normalizeValue($row['birth_date'] ?? null),
            'guardian_name' => $this->normalizeValue($row['guardian_name'] ?? null),
            'guardian_phone' => $this->normalizeValue($row['guardian_phone'] ?? null),
        ];

        $validator = Validator::make($normalized, [
            'name' => ['required', 'string', 'max:150', new NotSpreadsheetFormula],
            'email' => ['required', 'email', 'max:150', 'unique:users,email', new EmailHasMailExchangeRecord],
            'phone' => ['nullable', 'string', 'max:25', 'unique:users,phone'],
            'city' => ['nullable', 'string', 'max:100', new NotSpreadsheetFormula],
            'country' => ['nullable', 'string', 'max:100', new NotSpreadsheetFormula],
            'education_type' => ['nullable', Rule::in(['school', 'university', 'training'])],
            'curriculum_code' => ['nullable', 'string'],
            'stage_code' => ['nullable', 'string'],
            'grade' => ['nullable', 'integer', 'min:1', 'max:12'],
            'university_name' => ['nullable', 'string', new NotSpreadsheetFormula],
            'major_name' => ['nullable', 'string', new NotSpreadsheetFormula],
            'academic_level' => ['nullable', Rule::in(['diploma', 'bachelor', 'master'])],
            'course_field_code' => ['nullable', 'string'],
            'level' => ['nullable', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'birth_date' => ['nullable', 'date'],
            'guardian_name' => ['nullable', 'string', 'max:150', new NotSpreadsheetFormula],
            'guardian_phone' => ['nullable', 'string', 'max:25'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        if (! empty($data['curriculum_code'])) {
            $data['curriculum_id'] = $this->resolveByCode(Curriculum::class, $data['curriculum_code'], 'curriculum_code');
        }

        if (! empty($data['stage_code'])) {
            $data['stage_id'] = $this->resolveByCode(Stage::class, $data['stage_code'], 'stage_code');
        }

        if (! empty($data['course_field_code'])) {
            $data['course_field_id'] = $this->resolveByCode(CourseField::class, $data['course_field_code'], 'course_field_code');
        }

        if (! empty($data['university_name'])) {
            $data['university_id'] = $this->resolveByName(University::class, $data['university_name'], 'university_name');
        }

        if (! empty($data['major_name'])) {
            $data['major_id'] = $this->resolveByName(Major::class, $data['major_name'], 'major_name');
        }

        return $data;
    }

    /**
     * خلايا Excel الفارغة تصل غالباً كسلسلة فارغة "" لا null فعلي — تُعامَل
     * هنا كـ null موحَّد، وإلا فإن قاعدة unique (البريد/الهاتف) قد ترفض ثاني
     * صف بهاتف فارغ بحجة تكراره مع أول صف بهاتف فارغ أيضاً.
     */
    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function resolveByCode(string $modelClass, string $code, string $field): int
    {
        $record = $modelClass::where('code', $code)->first();

        if (! $record) {
            throw ValidationException::withMessages([
                $field => ["الرمز \"{$code}\" غير معروف"],
            ]);
        }

        return $record->id;
    }

    private function resolveByName(string $modelClass, string $name, string $field): int
    {
        $record = $modelClass::where('name_ar', $name)->first();

        if (! $record) {
            throw ValidationException::withMessages([
                $field => ["القيمة \"{$name}\" غير معروفة"],
            ]);
        }

        return $record->id;
    }
}
