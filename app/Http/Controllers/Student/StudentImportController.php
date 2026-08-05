<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\ImportStudentsRequest;
use App\Services\StudentImportService;

class StudentImportController extends Controller
{
    public function __construct(private readonly StudentImportService $studentImportService) {}

    public function store(ImportStudentsRequest $request)
    {
        $result = $this->studentImportService->import($request->file('file'), $request->user());

        return $this->success($result, 'اكتمل الاستيراد');
    }
}
