<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\AddAvailabilityTimeRequest;
use App\Models\Teacher;
use App\Models\TeacherAvailabilityTime;
use App\Services\AvailabilityService;

class TeacherAvailabilityTimeController extends Controller
{
    public function __construct(private readonly AvailabilityService $availabilityService) {}

    public function index(Teacher $teacher)
    {
        $this->authorize('viewAvailability', $teacher);

        return $this->success($teacher->availabilityTimes()->get());
    }

    public function store(AddAvailabilityTimeRequest $request, Teacher $teacher)
    {
        $time = $this->availabilityService->addTime($teacher, $request->validated());

        return $this->success($time, 'تمت إضافة وقت التوفّر بنجاح', 201);
    }

    public function destroy(Teacher $teacher, TeacherAvailabilityTime $time)
    {
        $this->authorize('update', $teacher);

        abort_unless($time->teacher_id === $teacher->id, 404);

        $this->availabilityService->removeTime($time);

        return $this->success(null, 'تم حذف وقت التوفّر');
    }
}
