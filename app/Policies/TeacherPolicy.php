<?php

namespace App\Policies;

use App\Models\Teacher;
use App\Models\User;

class TeacherPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Teacher $teacher): bool
    {
        return $user->isAdmin() || $user->id === $teacher->user_id;
    }

    /** ملف عام غير حساس (بلا وثائق توثيق) — أي مستخدم مسجَّل يراه لمعلم موثّق، والأدمن/صاحب الحساب دوماً */
    public function viewPublicProfile(User $user, Teacher $teacher): bool
    {
        return $user->isAdmin() || $user->id === $teacher->user_id || $teacher->isVerified();
    }

    /** بيانات توفر عامة غير حساسة — نفس شرط الملف العام (للحجز) */
    public function viewAvailability(User $user, Teacher $teacher): bool
    {
        return $this->viewPublicProfile($user, $teacher);
    }

    public function invite(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Teacher $teacher): bool
    {
        return $user->isAdmin() || $user->id === $teacher->user_id;
    }

    public function submitForVerification(User $user, Teacher $teacher): bool
    {
        return $user->id === $teacher->user_id;
    }

    public function approve(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reject(User $user): bool
    {
        return $user->isAdmin();
    }

    public function suspend(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reactivate(User $user): bool
    {
        return $user->isAdmin();
    }
}
