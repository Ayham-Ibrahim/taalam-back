<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher !== null;
    }

    public function view(User $user, Course $course): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $course->teacher_id;
    }

    public function create(User $user): bool
    {
        $teacher = $user->loadMissing('teacher')->teacher;

        return $teacher !== null && $teacher->isVerified() && $teacher->isTrainingCenter();
    }

    public function update(User $user, Course $course): bool
    {
        return $user->loadMissing('teacher')->teacher?->id === $course->teacher_id;
    }

    public function submit(User $user, Course $course): bool
    {
        return $user->loadMissing('teacher')->teacher?->id === $course->teacher_id;
    }

    public function disable(User $user, Course $course): bool
    {
        return $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $course->teacher_id;
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
