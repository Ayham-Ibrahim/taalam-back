<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\AddTeacherGalleryItemRequest;
use App\Models\Teacher;
use App\Models\TeacherGalleryItem;
use App\Services\TeacherService;

class TeacherGalleryItemController extends Controller
{
    public function __construct(private readonly TeacherService $teacherService) {}

    public function store(AddTeacherGalleryItemRequest $request, Teacher $teacher)
    {
        $item = $this->teacherService->addGalleryItem($teacher, $request->file('file'));

        return $this->success($item, 'تمت إضافة الملف بنجاح', 201);
    }

    public function destroy(TeacherGalleryItem $item)
    {
        $this->authorize('update', $item->teacher);

        $this->teacherService->removeGalleryItem($item);

        return $this->success(null, 'تم حذف الملف بنجاح');
    }
}
