<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\Package;
use App\Models\SupportPackage;
use App\Models\User;

class CouponPolicy
{
    /**
     * $package يُمرَّر من الراوت (coupons لباقة محدَّدة) — المعلم يرى كوبونات باقاته فقط، لا كل الباقات.
     * كوبونات باقات الدعم الأكاديمي (SupportPackage) للأدمن حصراً — لا صلاحية ذاتية للداعم (قرار صريح).
     */
    public function viewAny(User $user, Package|SupportPackage $package): bool
    {
        if ($package instanceof SupportPackage) {
            return $user->isAdmin();
        }

        return $user->isAdmin() || ($user->isTeacher() && $user->loadMissing('teacher')->teacher?->id === $package->teacher_id);
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $this->owns($user, $coupon);
    }

    /** نفس شرط viewAny بالضبط — لا Coupon بعد وقت الإنشاء، فالفحص على ملكية الباقة المستهدَفة */
    public function create(User $user, Package|SupportPackage $package): bool
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

        // كوبونات باقات الدعم الأكاديمي (support_package_id محدَّد، teacher_id دائماً null لها) للأدمن حصراً
        if ($coupon->support_package_id !== null) {
            return false;
        }

        return $user->isTeacher() && $user->loadMissing('teacher')->teacher?->id === $coupon->teacher_id;
    }
}
