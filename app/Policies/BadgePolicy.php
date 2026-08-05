<?php

namespace App\Policies;

use App\Models\User;

/**
 * كتالوج الشارات مرجع عام — القراءة مفتوحة لأي مستخدم مصادَق (نفس نمط
 * TaxonomyPolicy)، لا حاجة لصلاحية خاصة لعرضه.
 */
class BadgePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }
}
