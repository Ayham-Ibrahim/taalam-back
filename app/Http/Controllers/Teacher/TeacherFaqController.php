<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\AddTeacherFaqRequest;
use App\Models\Teacher;
use App\Models\TeacherFaq;
use App\Services\TeacherService;

class TeacherFaqController extends Controller
{
    public function __construct(private readonly TeacherService $teacherService) {}

    public function store(AddTeacherFaqRequest $request, Teacher $teacher)
    {
        $faq = $this->teacherService->addFaq($teacher, $request->validated());

        return $this->success($faq, 'تمت إضافة السؤال بنجاح', 201);
    }

    public function destroy(TeacherFaq $faq)
    {
        $this->authorize('update', $faq->teacher);

        $this->teacherService->removeFaq($faq);

        return $this->success(null, 'تم حذف السؤال بنجاح');
    }
}
