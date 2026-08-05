<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;

class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher !== null;
    }

    public function view(User $user, Package $package): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $package->teacher_id;
    }

    public function create(User $user): bool
    {
        $teacher = $user->loadMissing('teacher')->teacher;

        return $teacher !== null && $teacher->isVerified() && ! $teacher->isTrainingCenter();
    }

    public function update(User $user, Package $package): bool
    {
        return $user->loadMissing('teacher')->teacher?->id === $package->teacher_id;
    }

    public function submit(User $user, Package $package): bool
    {
        return $user->loadMissing('teacher')->teacher?->id === $package->teacher_id;
    }

    public function disable(User $user, Package $package): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $package->teacher_id;
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
