<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * غلاف رفيع فقط لقراءة الملف (صف العناوين + الاستخراج كمصفوفة) — كل منطق
 * الأعمال (تحقق، إنشاء، دعوة) في App\Services\TeacherImportService، الذي
 * يستدعي Excel::toCollection() مباشرة ولا يعتمد على collection() هنا.
 * يوازي App\Imports\StudentsImport تماماً.
 */
class TeachersImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows): void
    {
        //
    }
}
