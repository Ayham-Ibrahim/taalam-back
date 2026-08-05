<?php

namespace App\Policies;

use App\Models\ClassSession;
use App\Models\RescheduleRequest;
use App\Models\User;

class RescheduleRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent() || $user->isTeacher();
    }

    public function view(User $user, RescheduleRequest $rescheduleRequest): bool
    {
        return $user->isAdmin() || $user->id === $rescheduleRequest->requested_by;
    }

    /** فقط طرفا الجلسة الفعليان (المعلم صاحبها أو طالب حاضر فيها) يطلبان تغيير موعدها */
    public function create(User $user, ClassSession $session): bool
    {
        if ($user->loadMissing('teacher')->teacher?->id === $session->teacher_id) {
            return true;
        }

        $studentId = $user->loadMissing('student')->student?->id;

        return $studentId !== null && $session->attendees()->where('student_id', $studentId)->exists();
    }

    public function approve(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reject(User $user): bool
    {
        return $user->isAdmin();
    }
}
