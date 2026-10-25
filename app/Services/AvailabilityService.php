<?php

namespace App\Services;

use App\Models\AvailabilitySlot;
use App\Models\Teacher;
use App\Models\TeacherBlackout;
use Illuminate\Validation\ValidationException;

class AvailabilityService
{
    public function addSlot(Teacher $teacher, array $data): AvailabilitySlot
    {
        // أيام التوفر مصدر أيام الباقات الفردية فقط، والمراكز التدريبية لا تملك باقات
        if ($teacher->isTrainingCenter()) {
            throw ValidationException::withMessages([
                'teacher_type' => ['المراكز التدريبية ليس لها أيام توفّر — مواعيد الدورات تُحدَّد ضمن كل دورة'],
            ]);
        }

        return AvailabilitySlot::create(array_merge($data, ['teacher_id' => $teacher->id]));
    }

    public function removeSlot(AvailabilitySlot $slot): void
    {
        $slot->delete();
    }

    public function addBlackout(Teacher $teacher, array $data): TeacherBlackout
    {
        return TeacherBlackout::create(array_merge($data, ['teacher_id' => $teacher->id]));
    }

    public function removeBlackout(TeacherBlackout $blackout): void
    {
        $blackout->delete();
    }
}
