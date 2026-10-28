<?php

namespace App\Policies;

use App\Models\SupportPackagePurchase;
use App\Models\User;

class SupportPackagePurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin()
            || $user->loadMissing('student')->student !== null
            || $user->loadMissing('academicSupporter')->academicSupporter !== null;
    }

    public function view(User $user, SupportPackagePurchase $purchase): bool
    {
        return $user->isAdmin()
            || $user->loadMissing('student')->student?->id === $purchase->student_id
            || $user->loadMissing('academicSupporter')->academicSupporter?->id === $purchase->academic_supporter_id;
    }

    public function create(User $user): bool
    {
        $student = $user->loadMissing('student')->student;

        return $student !== null && $student->education_type === 'university';
    }
}
