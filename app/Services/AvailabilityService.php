<?php

namespace App\Services;

use App\Models\AvailabilitySlot;
use App\Models\Package;
use App\Models\Teacher;
use App\Models\TeacherAvailabilityTime;
use App\Models\TeacherBlackout;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($teacher, $data) {
            $slot = AvailabilitySlot::create(array_merge($data, ['teacher_id' => $teacher->id]));
            $this->syncTrialPackageDays($teacher);

            return $slot;
        });
    }

    public function removeSlot(AvailabilitySlot $slot): void
    {
        DB::transaction(function () use ($slot) {
            $slot->delete();
            $this->syncTrialPackageDays($slot->teacher);
        });
    }

    /**
     * أيام الجلسة التعريفية (is_trial) تتبع أيام توفّر المعلم دائماً — بخلاف باقاته العادية
     * التي يختار أيامها بنفسه. تُستدعى عند كل تغيير لأيام التوفّر، ومن TrialPackagesSeeder
     * كشبكة أمان. لا تلمس الباقة إن كانت أيامها مطابقة أصلاً.
     */
    public function syncTrialPackageDays(Teacher $teacher): void
    {
        $days = $teacher->availabilitySlots()->pluck('day_of_week')->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();

        $teacher->packages()->where('is_trial', true)->with('schedules:id,package_id,day_of_week')->get()
            ->each(function (Package $package) use ($days) {
                $current = $package->schedules->pluck('day_of_week')->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();

                if ($current === $days) {
                    return;
                }

                $package->schedules()->delete();

                foreach ($days as $day) {
                    $package->schedules()->create(['day_of_week' => $day]);
                }
            });
    }

    /**
     * نافذة توفّر أسبوعية معروضة في البروفايل ("أوقات التوفر هذا الأسبوع") —
     * منفصلة تماماً عن addSlot أعلاه (أيام فقط). حدّ أقصى للعدد فقط، والتحقق من
     * التداخل يتم في AddAvailabilityTimeRequest.
     */
    public function addTime(Teacher $teacher, array $data): TeacherAvailabilityTime
    {
        if ($teacher->availabilityTimes()->count() >= Teacher::MAX_AVAILABILITY_TIMES) {
            throw ValidationException::withMessages([
                'day_of_week' => ['لا يمكن إضافة أكثر من '.Teacher::MAX_AVAILABILITY_TIMES.' نافذة توفّر — احذف نافذة قديمة أولاً.'],
            ]);
        }

        return $teacher->availabilityTimes()->create([
            'day_of_week' => $data['day_of_week'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'sort_order' => ($teacher->availabilityTimes()->max('sort_order') ?? -1) + 1,
        ]);
    }

    public function removeTime(TeacherAvailabilityTime $time): void
    {
        $time->delete();
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
