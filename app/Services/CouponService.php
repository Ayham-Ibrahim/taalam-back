<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function create(Package $package, User $creator, array $data): Coupon
    {
        return Coupon::create([
            'code' => $this->normalizeCode($data['code'] ?? null) ?? $this->generateUniqueCode(),
            'package_id' => $package->id,
            'teacher_id' => $package->teacher_id,
            'created_by' => $creator->id,
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'redeemed_count' => 0,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => true,
        ]);
    }

    public function update(Coupon $coupon, array $data): Coupon
    {
        $coupon->update(array_intersect_key($data, array_flip([
            'discount_type', 'discount_value', 'max_redemptions', 'expires_at', 'is_active',
        ])));

        return $coupon->fresh();
    }

    /**
     * يُستدعى من BookingService داخل معاملة الحجز نفسها — lockForUpdate يمنع
     * معاملتين متزامنتين من "حجز" آخر استخدام متبقٍّ لنفس الكوبون معاً (سباق
     * على max_redemptions). عند نجاحها تزيد redeemed_count فوراً؛ إن ألغي
     * الحجز/انتهت مهلته لاحقاً بلا دفع، BookingService يستدعي release() لإعادتها.
     *
     * @return array{coupon: Coupon, discountAmount: float, teacherAmount: float, platformAmount: float, amountPaid: float}
     */
    public function validateAndReserve(string $code, Package $package): array
    {
        $coupon = Coupon::where('package_id', $package->id)
            ->where('code', $this->normalizeCode($code))
            ->lockForUpdate()
            ->first();

        if (! $coupon) {
            throw ValidationException::withMessages(['coupon_code' => ['كود الخصم غير صحيح لهذه الباقة.']]);
        }

        if (! $coupon->isRedeemable()) {
            throw ValidationException::withMessages(['coupon_code' => ['هذا الكود لم يعد صالحاً (منتهٍ أو معطَّل أو استُنفِد).']]);
        }

        $coupon->increment('redeemed_count');

        $teacherAmount = round($package->student_price - $package->platform_revenue, 2);
        $discountAmount = $coupon->isPercent()
            ? round((float) $package->student_price * (float) $coupon->discount_value / 100, 2)
            : round((float) $coupon->discount_value, 2);
        // مضمونة ≥ 0 لأن الخصم (نسبةً أو مبلغاً) لا يتجاوز platform_revenue دائماً
        // (مفروض وقت إنشاء/تعديل الكوبون — راجع CreateCouponRequest/UpdateCouponRequest)
        $platformAmount = max(0, round($package->platform_revenue - $discountAmount, 2));

        return [
            'coupon' => $coupon,
            'discountAmount' => $discountAmount,
            'teacherAmount' => $teacherAmount,
            'platformAmount' => $platformAmount,
            'amountPaid' => round($teacherAmount + $platformAmount, 2),
        ];
    }

    /** يُستدعى عند إلغاء/رفض/انتهاء حجز لم يُدفَع فيه فعلياً — يعيد الاستخدام المحجوز إلى الكوبون */
    public function release(?int $couponId): void
    {
        if ($couponId === null) {
            return;
        }

        Coupon::where('id', $couponId)->where('redeemed_count', '>', 0)->decrement('redeemed_count');
    }

    /** نفس تحرير release() لكن لعدة حجوزات دفعة واحدة (انتهاء صلاحية جماعي) */
    public function releaseMany(array $couponIds): void
    {
        $counts = array_count_values(array_filter($couponIds));

        foreach ($counts as $couponId => $count) {
            Coupon::where('id', $couponId)->where('redeemed_count', '>', 0)->decrement('redeemed_count', $count);
        }
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Coupon::where('code', $code)->exists());

        return $code;
    }

    private function normalizeCode(?string $code): ?string
    {
        return $code ? strtoupper(trim($code)) : null;
    }
}
