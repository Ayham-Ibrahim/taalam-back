<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\Package;
use App\Models\User;

class CouponPolicy
{
    /** $package يُمرَّر من الراوت (coupons لباقة محدَّدة) — المعلم يرى كوبونات باقاته فقط، لا كل الباقات */
    public function viewAny(User $user, Package $package): bool
    {
        return $user->isAdmin() || ($user->isTeacher() && $user->loadMissing('teacher')->teacher?->id === $package->teacher_id);
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $this->owns($user, $coupon);
    }

    /** نفس شرط viewAny بالضبط — لا Coupon بعد وقت الإنشاء، فالفحص على ملكية الباقة المستهدَفة */
    public function create(User $user, Package $package): bool
    {
        return $this->viewAny($user, $package);
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $this->owns($user, $coupon);
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $this->owns($user, $coupon);
    }

    private function owns(User $user, Coupon $coupon): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isTeacher() && $user->loadMissing('teacher')->teacher?->id === $coupon->teacher_id;
    }
}
