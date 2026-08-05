<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Teacher;
use App\Traits\LogsAuditEvents;
use Illuminate\Validation\ValidationException;

class CourseService
{
    use LogsAuditEvents;

    public function createDraft(Teacher $teacher, array $data): Course
    {
        if (! $teacher->isTrainingCenter()) {
            throw ValidationException::withMessages([
                'teacher_type' => ['الدورات التدريبية حصراً لحسابات المراكز التدريبية'],
            ]);
        }

        if (! $teacher->isVerified()) {
            throw ValidationException::withMessages([
                'teacher_status' => ['يجب توثيق حساب المركز أولاً قبل إنشاء دورة'],
            ]);
        }

        $curriculumIds = $data['curriculum_ids'] ?? [];
        $schedules = $data['schedules'] ?? [];
        unset($data['curriculum_ids'], $data['schedules']);

        $course = Course::create(array_merge($data, [
            'teacher_id' => $teacher->id,
            'status' => 'draft',
        ]));

        if ($curriculumIds) {
            $course->curricula()->sync($curriculumIds);
        }

        if ($schedules) {
            $this->syncSchedules($course, $schedules);
        }

        return $course->fresh(['curricula', 'schedules']);
    }

    public function updateDraft(Course $course, array $data): Course
    {
        if ($course->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعديل الدورة إلا في حالة المسودة — عطّل الدورة الحالية وأنشئ دورة جديدة بدلاً من ذلك'],
            ]);
        }

        $curriculumIds = $data['curriculum_ids'] ?? null;
        $schedules = $data['schedules'] ?? null;
        unset($data['curriculum_ids'], $data['schedules']);

        $course->fill($data);
        $course->save();

        if ($curriculumIds !== null) {
            $course->curricula()->sync($curriculumIds);
        }

        if ($schedules !== null) {
            $this->syncSchedules($course, $schedules);
        }

        return $course->fresh(['curricula', 'schedules']);
    }

    public function submitForApproval(Course $course): Course
    {
        if ($course->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن إرسال الدورة للمراجعة من هذه الحالة'],
            ]);
        }

        $course->update(['status' => 'pending_approval', 'submitted_at' => now()]);

        $this->audit('course.submitted', $course);

        return $course;
    }

    public function disable(Course $course): Course
    {
        if (! in_array($course->status, ['active', 'full', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعطيل الدورة من هذه الحالة'],
            ]);
        }

        $course->update(['status' => 'disabled']);

        $this->audit('course.disabled', $course);

        return $course;
    }

    private function syncSchedules(Course $course, array $schedules): void
    {
        foreach ($schedules as $schedule) {
            if (($schedule['end_time'] ?? null) <= ($schedule['start_time'] ?? null)) {
                throw ValidationException::withMessages([
                    'schedules' => ['وقت النهاية يجب أن يكون بعد وقت البداية في كل موعد'],
                ]);
            }
        }

        $course->schedules()->delete();

        foreach ($schedules as $schedule) {
            $course->schedules()->create($schedule);
        }
    }
}
