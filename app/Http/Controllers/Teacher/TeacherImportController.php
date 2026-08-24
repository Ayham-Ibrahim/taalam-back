<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\ImportTeachersRequest;
use App\Http\Resources\Admin\ImportBatchResource;
use App\Jobs\ProcessTeacherImportJob;
use App\Models\ImportBatch;

class TeacherImportController extends Controller
{
    public function store(ImportTeachersRequest $request)
    {
        $file = $request->file('file');
        $path = $file->store('imports/teachers', 'local');

        $batch = ImportBatch::create([
            'type' => 'teacher',
            'status' => 'queued',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'created_by' => $request->user()->id,
        ]);

        ProcessTeacherImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'تم استلام الملف، جارٍ معالجته في الخلفية', 202);
    }
}
