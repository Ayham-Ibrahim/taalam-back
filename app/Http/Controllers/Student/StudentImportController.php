<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\ImportStudentsRequest;
use App\Http\Resources\Admin\ImportBatchResource;
use App\Jobs\ProcessStudentImportJob;
use App\Models\ImportBatch;

class StudentImportController extends Controller
{
    public function store(ImportStudentsRequest $request)
    {
        $file = $request->file('file');
        $path = $file->store('imports/students', 'local');

        $batch = ImportBatch::create([
            'type' => 'student',
            'status' => 'queued',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'created_by' => $request->user()->id,
        ]);

        ProcessStudentImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'تم استلام الملف، جارٍ معالجته في الخلفية', 202);
    }
}
