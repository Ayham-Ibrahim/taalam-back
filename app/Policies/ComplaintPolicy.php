<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent();
    }

    public function view(User $user, Complaint $complaint): bool
    {
        return $user->isAdmin() || $user->loadMissing('student')->student?->id === $complaint->student_id;
    }

    public function create(User $user): bool
    {
        return $user->loadMissing('student')->student !== null;
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
