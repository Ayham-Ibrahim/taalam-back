<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent() || $user->isTeacher();
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        return $user->isAdmin()
            || $user->loadMissing('student')->student?->id === $enrollment->student_id
            || $user->loadMissing('teacher')->teacher?->id === $enrollment->teacher_id;
    }

    public function create(User $user): bool
    {
        return $user->loadMissing('student')->student !== null;
    }

    public function createManual(User $user): bool
    {
        return $user->isAdmin();
    }
}
