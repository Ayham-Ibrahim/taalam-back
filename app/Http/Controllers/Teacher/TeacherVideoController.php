<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\AddTeacherVideoRequest;
use App\Models\Teacher;
use App\Models\TeacherVideo;
use App\Services\TeacherService;

class TeacherVideoController extends Controller
{
    public function __construct(private readonly TeacherService $teacherService) {}

    public function store(AddTeacherVideoRequest $request, Teacher $teacher)
    {
        $video = $this->teacherService->addVideo($teacher, $request->validated());

        return $this->success($video, 'تمت إضافة الفيديو بنجاح', 201);
    }

    public function destroy(TeacherVideo $video)
    {
        $this->authorize('update', $video->teacher);

        $this->teacherService->removeVideo($video);

        return $this->success(null, 'تم حذف الفيديو بنجاح');
    }
}
