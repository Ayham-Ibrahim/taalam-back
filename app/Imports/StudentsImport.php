<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * غلاف رفيع فقط لقراءة الملف (صف العناوين + الاستخراج كمصفوفة) — كل منطق
 * الأعمال (تحقق، إنشاء، إشعار) في App\Services\StudentImportService، الذي
 * يستدعي Excel::toCollection() مباشرة ولا يعتمد على collection() هنا.
 */
class StudentsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows): void
    {
        //
    }
}
