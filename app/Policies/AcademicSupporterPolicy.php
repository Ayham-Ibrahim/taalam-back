<?php

namespace App\Policies;

use App\Models\AcademicSupporter;
use App\Models\User;

class AcademicSupporterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isAcademicSupport();
    }

    public function view(User $user, AcademicSupporter $supporter): bool
    {
        return $user->isAdmin() || $user->id === $supporter->user_id;
    }

    /** لا تسجيل ذاتي — الأدمن فقط يضيف حساب داعم أكاديمي */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AcademicSupporter $supporter): bool
    {
        return $user->isAdmin() || $user->id === $supporter->user_id;
    }

    public function suspend(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reactivate(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }

    public function resetPassword(User $user): bool
    {
        return $user->isAdmin();
    }
}
