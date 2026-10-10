<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->loadMissing('student')->student !== null;
    }

    public function update(User $user, Review $review): bool
    {
        return $user->loadMissing('student')->student?->id === $review->student_id;
    }

    public function respond(User $user, Review $review): bool
    {
        return $user->loadMissing('teacher')->teacher?->id === $review->teacher_id;
    }

    public function hide(User $user): bool
    {
        return $user->isAdmin();
    }

    public function unhide(User $user): bool
    {
        return $user->isAdmin();
    }

    public function report(User $user): bool
    {
        return $user->loadMissing('student')->student !== null;
    }

    /** رفع ملف Excel بتقييمات يدوية لمعلم — ميزة مؤقتة لإدخال بيانات تاريخية */
    public function import(User $user): bool
    {
        return $user->isAdmin();
    }

    /** حذف تقييم مُستورَد يدوياً فقط — التحقق الفعلي من is_seeded يتم في الـ Controller */
    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }
}
