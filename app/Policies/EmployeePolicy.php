<?php

namespace App\Policies;

use App\Models\User;

/** الموظفون ورواتبهم وبونصاتهم وتقريرهم — مالية: الأدمن والمحاسب فقط */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFinance();
    }

    public function manage(User $user): bool
    {
        return $user->isFinance();
    }
}
