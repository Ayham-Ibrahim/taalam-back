<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/** غلاف رفيع فقط — نفس نمط StudentsImport بالضبط. المنطق الفعلي في ReviewImportService. */
class ReviewsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows): void
    {
        //
    }
}
