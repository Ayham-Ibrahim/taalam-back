<?php

namespace App\Services;

use App\Imports\TeachersImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
 */
class TeacherImportService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TeacherService $teacherService,
    ) {}

    public function import(UploadedFile $file, User $admin): array
    {
        $sheets = Excel::toCollection(new TeachersImport, $file);
        $rows = $sheets->first() ?? collect();

        $maxRows = (int) $this->settings->get('teacher_import_max_rows', 500);

        if ($rows->count() > $maxRows) {
            throw ValidationException::withMessages([
                'file' => ["عدد الصفوف يتجاوز الحد الأقصى المسموح ({$maxRows} صف)"],
            ]);
        }

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
        }

        return [
            'imported' => $imported,
            'failed' => count($errors),
            'errors' => $errors,
        ];
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
