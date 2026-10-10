<?php

namespace App\Services;

use App\Imports\ReviewsImport;
use App\Models\Review;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

/**
 * استيراد تقييمات يدوية (ملف Excel/CSV) لمعلم معيّن — ميزة مؤقتة لإدخال
 * بيانات تاريخية لا طالب حقيقي وراءها (راجع migration إضافة reviewer_name/
 * is_seeded لجدول reviews للسبب الكامل). بعكس StudentImportService/
 * TeacherImportService، هذه مُعالَجة متزامنة (بلا ImportBatch/Job/قائمة
 * انتظار) عمداً: حجم ملف تقييمات معلم واحد صغير دائماً (عشرات الصفوف)،
 * فلا داعي لتعقيد المعالجة الخلفية لميزة وُصفت صراحة بأنها مؤقتة.
 */
class ReviewImportService
{
    private const MAX_ROWS = 500;

    /** @return array{imported: int, failed: int, errors: array<int, array{row: int, errors: array}>} */
    public function importForTeacher(Teacher $teacher, UploadedFile $file, User $admin): array
    {
        $sheets = Excel::toCollection(new ReviewsImport, $file);
        $rows = $sheets->first() ?? collect();

        if ($rows->count() > self::MAX_ROWS) {
            return [
                'imported' => 0,
                'failed' => 0,
                'errors' => [['row' => 0, 'errors' => ['file' => ["الملف يتجاوز الحد الأقصى (".self::MAX_ROWS." صف)."]]]],
            ];
        }

        $imported = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // صف العناوين + فهرسة من صفر

            try {
                $this->importRow($row->toArray(), $teacher, $admin);
                $imported++;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $errors[] = ['row' => $rowNumber, 'errors' => $e->errors()];
            }
        }

        return ['imported' => $imported, 'failed' => count($errors), 'errors' => $errors];
    }

    private function importRow(array $row, Teacher $teacher, User $admin): void
    {
        $data = [
            'rating' => $this->normalize($row['rating'] ?? null),
            'reviewer_name' => $this->normalize($row['reviewer_name'] ?? null),
            'comment' => $this->normalize($row['comment'] ?? null),
            'review_date' => $this->normalize($row['review_date'] ?? null),
        ];

        $validator = Validator::make($data, [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'reviewer_name' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'review_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        $validated = $validator->validated();

        $review = Review::create([
            'teacher_id' => $teacher->id,
            'student_id' => null,
            'reviewer_name' => $validated['reviewer_name'],
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'is_seeded' => true,
            'imported_by' => $admin->id,
        ]);

        // تاريخ مُخصَّص (إن وُجد) — يُضبَط بعد الإنشاء لأن created_at تُدار تلقائياً
        // عبر Eloquent timestamps ولا يمكن تمريرها ضمن create() مباشرة.
        if (! empty($validated['review_date'])) {
            $review->created_at = $validated['review_date'];
            $review->save();
        }
    }

    /** يحوّل خلية Excel الفارغة (سلسلة فارغة) إلى null حقيقي — نفس نمط StudentImportService::normalizeValue */
    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
