<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Teacher;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PackageService
{
    use LogsAuditEvents;

    public function __construct(private readonly SettingsService $settings) {}

    public function createDraft(Teacher $teacher, array $data): Package
    {
        if ($teacher->isTrainingCenter()) {
            throw ValidationException::withMessages([
                'teacher_type' => ['المراكز التدريبية لا يمكنها إنشاء باقات — الدورات التدريبية فقط'],
            ]);
        }

        if (! $teacher->isVerified()) {
            throw ValidationException::withMessages([
                'teacher_status' => ['يجب توثيق الحساب أولاً قبل إنشاء باقة'],
            ]);
        }

        $curriculumIds = $data['curriculum_ids'] ?? [];
        $stageIds = $data['stage_ids'] ?? [];
        $schedules = $data['schedules'] ?? [];
        unset($data['curriculum_ids'], $data['stage_ids'], $data['schedules']);

        // أرقام صفوف صِرفة (1-12) بلا جدول تصنيف — عمود JSON مباشر على الباقة
        // لا علاقة pivot تحتاج sync()، بعكس stage_ids/curriculum_ids.
        $data['grades'] = ! empty($data['grades']) ? array_values($data['grades']) : null;

        $package = Package::create(array_merge($data, [
            'teacher_id' => $teacher->id,
            'status' => 'draft',
            // منطقة المعلم وقت إنشاء الجدول — تُستخدَم لاحقاً لتحويل أوقات package_schedules
            // الخام (start_time) إلى UTC بدقة بدل افتراضها UTC ساذجاً (BookingService::sessionsFor)
            'schedule_timezone' => $teacher->loadMissing('user')->user->timezone ?? 'UTC',
        ]));

        if ($curriculumIds) {
            $package->curricula()->sync($curriculumIds);
        }

        if ($stageIds) {
            $package->stages()->sync($stageIds);
        }

        if ($schedules) {
            $this->syncSchedules($package, $schedules, $teacher);
        }

        return $package->fresh(['curricula', 'stages', 'schedules']);
    }

    public function updateDraft(Package $package, array $data): Package
    {
        if ($package->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعديل الباقة إلا في حالة المسودة — عطّل الباقة الحالية وأنشئ باقة جديدة بدلاً من ذلك'],
            ]);
        }

        $curriculumIds = $data['curriculum_ids'] ?? null;
        $stageIds = $data['stage_ids'] ?? null;
        $schedules = $data['schedules'] ?? null;
        unset($data['curriculum_ids'], $data['stage_ids'], $data['schedules']);

        if (array_key_exists('grades', $data) && empty($data['grades'])) {
            $data['grades'] = null;
        }

        $package->fill($data);
        $package->save();

        if ($curriculumIds !== null) {
            $package->curricula()->sync($curriculumIds);
        }

        if ($stageIds !== null) {
            $package->stages()->sync($stageIds);
        }

        if ($schedules !== null) {
            $this->syncSchedules($package, $schedules, $package->teacher);
        }

        return $package->fresh(['curricula', 'stages', 'schedules']);
    }

    public function submitForApproval(Package $package): Package
    {
        if ($package->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن إرسال الباقة للمراجعة من هذه الحالة'],
            ]);
        }

        $package->update(['status' => 'pending_approval', 'submitted_at' => now()]);

        $this->audit('package.submitted', $package);

        return $package;
    }

    public function disable(Package $package): Package
    {
        if (! in_array($package->status, ['active', 'full'], true)) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعطيل الباقة من هذه الحالة'],
            ]);
        }

        $package->update(['status' => 'disabled']);

        $this->audit('package.disabled', $package);

        return $package;
    }

    /**
     * الجدول الثابت — يُستبدل بالكامل مع كل تحديث، وليس تعديلاً تراكمياً.
     *
     * جماعية: كل صف هو تاريخ جلسة فعلي يختاره المعلم صراحةً من روزنامة — لا تكرار
     *   أسبوعي تلقائي إطلاقاً، فيجب أن يساوي عدد الصفوف sessions_count بالضبط.
     *   day_of_week يُشتق من كل تاريخ، وend_time من إعداد session_duration_minutes
     *   (ثابتة، غير قابلة لتحكم المعلم).
     * فردية: المعلم يحدد فقط الأيام المتاحة لهذه الباقة — يجب أن تكون كل يوم
     *   ضمن أيام توفّره العامة (availability_slots)، وإلا رُفض الطلب. لا تاريخ
     *   ولا وقت على مستوى الباقة إطلاقاً — الطالب يحددهما لاحقاً عبر طلب حجز.
     */
    private function syncSchedules(Package $package, array $schedules, Teacher $teacher): void
    {
        $package->schedules()->delete();

        if ($package->session_format === 'individual') {
            $availableDays = $teacher->availabilitySlots()->pluck('day_of_week')->all();

            foreach ($schedules as $schedule) {
                $day = (int) $schedule['day_of_week'];

                if (! in_array($day, $availableDays, true)) {
                    throw ValidationException::withMessages([
                        'schedules' => ['اليوم المحدد ليس ضمن أيام توفّرك — أضِفه أولاً من إعدادات التوفر'],
                    ]);
                }

                $package->schedules()->create(['day_of_week' => $day]);
            }

            return;
        }

        if (count($schedules) !== $package->sessions_count) {
            throw ValidationException::withMessages([
                'schedules' => ["يجب تحديد تاريخ لكل جلسة من جلسات الباقة ({$package->sessions_count}) — لا تكرار تلقائي"],
            ]);
        }

        $durationMinutes = (int) $this->settings->get('session_duration_minutes', 60);

        foreach ($schedules as $schedule) {
            $date = Carbon::parse($schedule['date']);
            $endTime = Carbon::parse($schedule['start_time'])->addMinutes($durationMinutes)->format('H:i');

            $package->schedules()->create([
                'date' => $date->toDateString(),
                'day_of_week' => $date->dayOfWeek,
                'start_time' => $schedule['start_time'],
                'end_time' => $endTime,
            ]);
        }
    }
}
