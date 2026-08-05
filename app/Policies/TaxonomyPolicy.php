<?php

namespace App\Policies;

use App\Models\User;

/**
 * تُدار كل جداول التصنيفات (المناهج/المراحل/المواد/الجامعات/التخصصات/
 * مجالات الدورات/اللغات) بنفس القاعدة: قراءة عامة، كتابة للأدمن فقط.
 */
class TaxonomyPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }
}
