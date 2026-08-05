<?php

namespace App\Policies;

use App\Models\ClassSession;
use App\Models\User;

class ClassSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher() || $user->isStudent();
    }

    public function view(User $user, ClassSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->loadMissing('teacher')->teacher?->id === $session->teacher_id) {
            return true;
        }

        $studentId = $user->loadMissing('student')->student?->id;

        return $studentId !== null && $session->attendees()->where('student_id', $studentId)->exists();
    }
}
