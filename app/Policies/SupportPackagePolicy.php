<?php

namespace App\Policies;

use App\Models\SupportPackage;
use App\Models\User;

class SupportPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher !== null;
    }

    public function view(User $user, SupportPackage $supportPackage): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $supportPackage->teacher_id;
    }

    public function create(User $user): bool
    {
        $teacher = $user->loadMissing('teacher')->teacher;

        return $teacher !== null && $teacher->isAcademicSupport() && $teacher->isVerified();
    }

    public function approve(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reject(User $user): bool
    {
        return $user->isAdmin();
    }

    public function disable(User $user, SupportPackage $supportPackage): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $supportPackage->teacher_id;
    }

    public function enable(User $user, SupportPackage $supportPackage): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $supportPackage->teacher_id;
    }
}
