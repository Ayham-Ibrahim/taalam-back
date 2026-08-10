<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\ImportTeachersRequest;
use App\Services\TeacherImportService;

class TeacherImportController extends Controller
{
    public function __construct(private readonly TeacherImportService $teacherImportService) {}

    public function store(ImportTeachersRequest $request)
    {
        $result = $this->teacherImportService->import($request->file('file'), $request->user());

        return $this->success($result, 'اكتمل الاستيراد');
    }
}
