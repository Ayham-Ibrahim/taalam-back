<?php

namespace App\Http\Controllers\Package;

use App\Http\Controllers\Controller;
use App\Http\Requests\Package\AvailabilitySlotRequest;
use App\Http\Requests\Package\TeacherBlackoutRequest;
use App\Models\AvailabilitySlot;
use App\Models\Teacher;
use App\Models\TeacherBlackout;
use App\Services\AvailabilityService;

class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availabilityService) {}

    public function indexSlots(Teacher $teacher)
    {
        $this->authorize('viewAvailability', $teacher);

        return $this->success($teacher->availabilitySlots()->orderBy('day_of_week')->get());
    }

    public function storeSlot(AvailabilitySlotRequest $request, Teacher $teacher)
    {
        $slot = $this->availabilityService->addSlot($teacher, $request->validated());

        return $this->success($slot, 'تمت إضافة الموعد بنجاح', 201);
    }

    public function destroySlot(Teacher $teacher, AvailabilitySlot $slot)
    {
        $this->authorize('update', $teacher);

        $this->availabilityService->removeSlot($slot);

        return $this->success(null, 'تم حذف الموعد');
    }

    public function storeBlackout(TeacherBlackoutRequest $request, Teacher $teacher)
    {
        $blackout = $this->availabilityService->addBlackout($teacher, $request->validated());

        return $this->success($blackout, 'تمت إضافة فترة الإجازة بنجاح', 201);
    }

    public function destroyBlackout(Teacher $teacher, TeacherBlackout $blackout)
    {
        $this->authorize('update', $teacher);

        $this->availabilityService->removeBlackout($blackout);

        return $this->success(null, 'تم حذف فترة الإجازة');
    }
}
