<?php

namespace App\Services;

use App\Imports\TeachersImport;
use App\Models\ImportBatch;
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

            try {
                $data = $this->validateRow($row->toArray());
                $this->teacherService->invite($data, $admin);
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

    private function validateRow(array $row): array
    {
        $normalized = [
            'name' => $row['name'] ?? null,
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'teacher_type' => $row['teacher_type'] ?? null,
        ];

        $validator = Validator::make($normalized, [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'unique:users,phone'],
            'teacher_type' => ['required', Rule::in(['school', 'university', 'training_center'])],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
