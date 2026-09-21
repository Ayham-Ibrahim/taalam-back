<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\AddTeacherExperienceRequest;
use App\Http\Requests\Teacher\UpdateTeacherExperienceRequest;
use App\Models\Teacher;
use App\Models\TeacherExperience;
use App\Services\TeacherService;

class TeacherExperienceController extends Controller
{
    public function __construct(private readonly TeacherService $teacherService) {}

    public function store(AddTeacherExperienceRequest $request, Teacher $teacher)
    {
        $experience = $this->teacherService->addExperience($teacher, $request->validated());

        return $this->success($experience, 'تمت إضافة الخبرة بنجاح', 201);
    }

    public function update(UpdateTeacherExperienceRequest $request, TeacherExperience $experience)
    {
        $experience = $this->teacherService->updateExperience($experience, $request->validated());

        return $this->success($experience, 'تم تعديل الخبرة بنجاح');
    }

    public function destroy(TeacherExperience $experience)
    {
        $this->authorize('update', $experience->teacher);

        $this->teacherService->removeExperience($experience);

        return $this->success(null, 'تم حذف الخبرة بنجاح');
    }
}
