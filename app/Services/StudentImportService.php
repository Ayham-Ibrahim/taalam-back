<?php

namespace App\Services;

use App\Imports\StudentsImport;
use App\Models\AccountInvitation;
use App\Models\CourseField;
use App\Models\Curriculum;
use App\Models\Major;
use App\Models\Stage;
use App\Models\Student;
use App\Models\University;
use App\Models\User;
use App\Notifications\StudentImported;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * استيراد جماعي للطلاب من Excel/CSV. فشل صف واحد لا يوقف الدفعة — كل صف
 * مستقل بمعاملته الخاصة، والأخطاء تُجمَع وتُعاد للأدمن مع رقم الصف.
 */
class StudentImportService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {}

    public function import(UploadedFile $file, User $admin): array
    {
        $sheets = Excel::toCollection(new StudentsImport, $file);
        $rows = $sheets->first() ?? collect();

        $maxRows = (int) $this->settings->get('student_import_max_rows', 500);

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
                $this->importRow($row->toArray(), $admin);
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

    private function importRow(array $row, User $admin): void
    {
        $data = $this->validateRow($row);

        DB::transaction(function () use ($data, $admin) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'student',
                'password' => null,
            ]);

            Student::create([
                'user_id' => $user->id,
                'education_type' => $data['education_type'],
                'curriculum_id' => $data['curriculum_id'] ?? null,
                'stage_id' => $data['stage_id'] ?? null,
                'grade' => $data['grade'] ?? null,
                'university_id' => $data['university_id'] ?? null,
                'major_id' => $data['major_id'] ?? null,
                'academic_level' => $data['academic_level'] ?? null,
                'course_field_id' => $data['course_field_id'] ?? null,
                'level' => $data['level'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
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

            $this->notifications->send($user, new StudentImported($invitation), 'student.imported');
        });
    }

    private function validateRow(array $row): array
    {
        $normalized = [
            'name' => $row['name'] ?? null,
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'education_type' => $row['education_type'] ?? null,
            'curriculum_code' => $row['curriculum_code'] ?? null,
            'stage_code' => $row['stage_code'] ?? null,
            'grade' => $row['grade'] ?? null,
            'university_name' => $row['university_name'] ?? null,
            'major_name' => $row['major_name'] ?? null,
            'academic_level' => $row['academic_level'] ?? null,
            'course_field_code' => $row['course_field_code'] ?? null,
            'level' => $row['level'] ?? null,
            'birth_date' => $row['birth_date'] ?? null,
            'guardian_name' => $row['guardian_name'] ?? null,
            'guardian_phone' => $row['guardian_phone'] ?? null,
        ];

        $validator = Validator::make($normalized, [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'unique:users,phone'],
            'education_type' => ['required', Rule::in(['school', 'university', 'training'])],
            'curriculum_code' => ['required_if:education_type,school'],
            'stage_code' => ['required_if:education_type,school'],
            'grade' => ['nullable', 'integer', 'min:1', 'max:12'],
            'university_name' => ['required_if:education_type,university'],
            'major_name' => ['required_if:education_type,university'],
            'academic_level' => ['nullable', 'required_if:education_type,university', Rule::in(['diploma', 'bachelor', 'master'])],
            'course_field_code' => ['required_if:education_type,training'],
            'level' => ['nullable', 'required_if:education_type,training', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'birth_date' => ['nullable', 'date'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
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
