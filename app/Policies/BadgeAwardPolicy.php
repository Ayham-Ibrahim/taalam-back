<?php

namespace App\Policies;

use App\Models\User;

class BadgeAwardPolicy
{
    public function grant(User $user): bool
    {
        return $user->isAdmin();
    }

    public function revoke(User $user): bool
    {
        return $user->isAdmin();
    }
}
