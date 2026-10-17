<?php

namespace App\Http\Controllers\AcademicSupporter;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicSupporter\AddAcademicSupporterGalleryItemRequest;
use App\Models\AcademicSupporter;
use App\Models\AcademicSupporterGalleryItem;
use App\Services\AcademicSupporterService;

class AcademicSupporterGalleryItemController extends Controller
{
    public function __construct(private readonly AcademicSupporterService $service) {}

    public function store(AddAcademicSupporterGalleryItemRequest $request, AcademicSupporter $academicSupporter)
    {
        $item = $this->service->addGalleryItem($academicSupporter, $request->file('file'), $request->file('thumbnail'));

        return $this->success($item, 'تمت إضافة الملف بنجاح', 201);
    }

    public function destroy(AcademicSupporterGalleryItem $item)
    {
        $this->authorize('update', $item->academicSupporter);

        $this->service->removeGalleryItem($item);

        return $this->success(null, 'تم حذف الملف بنجاح');
    }
}
