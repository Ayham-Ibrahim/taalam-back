<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent() || $user->isTeacher();
    }

    public function view(User $user, Complaint $complaint): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $user->loadMissing(['student', 'teacher']);

        return ($user->student && $user->student->id === $complaint->student_id)
            || ($user->teacher && $user->teacher->id === $complaint->teacher_id);
    }

    public function create(User $user): bool
    {
        $user->loadMissing(['student', 'teacher']);

        return $user->student !== null || $user->teacher !== null;
    }

    public function resolve(User $user): bool
    {
        return $user->isAdmin();
    }

    public function escalate(User $user): bool
    {
        return $user->isAdmin();
    }
}
