<?php

namespace App\Policies;

use App\Models\SupportPackage;
use App\Models\User;

class SupportPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->loadMissing('academicSupporter')->academicSupporter !== null;
    }

    public function view(User $user, SupportPackage $supportPackage): bool
    {
        return $user->isAdmin() || $user->loadMissing('academicSupporter')->academicSupporter?->id === $supportPackage->academic_supporter_id;
    }

    /** الأدمن حصراً ينشئ باقات الدعم ويربطها بالداعم الذي يختاره — لا إنشاء ذاتي من الداعم */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
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
        return $user->isAdmin() || $user->loadMissing('academicSupporter')->academicSupporter?->id === $supportPackage->academic_supporter_id;
    }

    public function enable(User $user, SupportPackage $supportPackage): bool
    {
        return $user->isAdmin() || $user->loadMissing('academicSupporter')->academicSupporter?->id === $supportPackage->academic_supporter_id;
    }
}
