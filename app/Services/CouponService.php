<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Package;
use App\Models\SupportPackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    /** يوازي create() تماماً لكن لباقة دعم أكاديمي — الأدمن فقط ينشئها (راجع SupportPackageCouponPolicy) */
    public function createForSupportPackage(SupportPackage $supportPackage, User $creator, array $data): Coupon
    {
        return Coupon::create([
            'code' => $this->normalizeCode($data['code'] ?? null) ?? $this->generateUniqueCode(),
            'support_package_id' => $supportPackage->id,
            'created_by' => $creator->id,
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'redeemed_count' => 0,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => true,
        ]);
    }

    /** كوبون أدمن واحد يُطبَّق على عدة باقات — package_id/support_package_id يبقيان null، الباقات في coupon_package */
    public function createMultiPackage(User $creator, array $data): Coupon
    {
        return DB::transaction(function () use ($creator, $data) {
            $coupon = Coupon::create([
                'code' => $this->normalizeCode($data['code'] ?? null) ?? $this->generateUniqueCode(),
                'created_by' => $creator->id,
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'],
                'max_redemptions' => $data['max_redemptions'] ?? null,
                'redeemed_count' => 0,
                'expires_at' => $data['expires_at'] ?? null,
                'is_active' => true,
            ]);

            $coupon->packages()->sync($data['package_ids'] ?? []);
            $coupon->courses()->sync($data['course_ids'] ?? []);

            return $coupon->load(['packages:id,title', 'courses:id,title']);
        });
    }

    public function updateMultiPackage(Coupon $coupon, array $data): Coupon
    {
        return DB::transaction(function () use ($coupon, $data) {
            $coupon->update(array_intersect_key($data, array_flip([
                'discount_type', 'discount_value', 'max_redemptions', 'expires_at', 'is_active',
            ])));

            if (array_key_exists('package_ids', $data)) {
                $coupon->packages()->sync($data['package_ids'] ?? []);
            }

            if (array_key_exists('course_ids', $data)) {
                $coupon->courses()->sync($data['course_ids'] ?? []);
            }

            return $coupon->fresh()->load(['packages:id,title', 'courses:id,title']);
        });
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
        // كوبون الباقة الواحدة (package_id) أو كوبون أدمن متعدد الباقات (coupon_package) — الكود فريد عالمياً فلا تعارض
        $coupon = Coupon::where('code', $this->normalizeCode($code))
            ->where(fn ($q) => $q->where('package_id', $package->id)
                ->orWhere(fn ($m) => $m->multiPackage()->whereHas('packages', fn ($p) => $p->where('packages.id', $package->id))))
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
        // كوبون متعدد الباقات: هامش باقة مشمولة قد يتغيّر بعد إنشاء الكوبون (إعادة اعتماد) —
        // الخصم لا يتجاوز حصة المنصة أبداً كي لا يمس مستحق المعلم
        if ($coupon->isMultiPackage()) {
            $discountAmount = min($discountAmount, round((float) $package->platform_revenue, 2));
        }
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

    /**
     * يوازي validateAndReserve() لكن لباقة دعم أكاديمي — لا هامش منصة لحمايته هنا
     * (platform_revenue=0 دائماً لهذا النوع، التسوية مع الداعم تتم يدوياً خارج
     * المنصة)، فالسقف الآمن الوحيد هو ألا يتجاوز الخصم سعر الباقة نفسه: نسبة
     * محدودة بـ 100%، مبلغ ثابت محدود بـ student_price (مفروض في
     * CreateSupportPackageCouponRequest وقت الإنشاء، لا حاجة لإعادة التحقق هنا
     * لأن السعر لا يتغيّر بعد إنشاء الكوبون).
     *
     * @return array{coupon: Coupon, discountAmount: float, amountPaid: float}
     */
    public function validateAndReserveForSupportPackage(string $code, SupportPackage $supportPackage): array
    {
        $coupon = Coupon::where('support_package_id', $supportPackage->id)
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

        $price = (float) $supportPackage->student_price;
        $discountAmount = $coupon->isPercent()
            ? round($price * (float) $coupon->discount_value / 100, 2)
            : round((float) $coupon->discount_value, 2);
        $amountPaid = max(0, round($price - $discountAmount, 2));

        return [
            'coupon' => $coupon,
            'discountAmount' => min($discountAmount, $price),
            'amountPaid' => $amountPaid,
        ];
    }

    /**
     * يوازي validateAndReserve() لكن لتسجيل في دورة مركز تدريبي — فقط كوبونات الأدمن
     * متعددة الباقات التي تشمل هذه الدورة (coupon_course). الخصم من حصة المنصة فقط،
     * فمستحق المركز (provider_amount) لا يتأثر.
     *
     * @return array{coupon: Coupon, discountAmount: float, providerAmount: float, platformAmount: float, amountPaid: float}
     */
    public function validateAndReserveForCourse(string $code, Course $course): array
    {
        $coupon = Coupon::where('code', $this->normalizeCode($code))
            ->multiPackage()
            ->whereHas('courses', fn ($c) => $c->where('courses.id', $course->id))
            ->lockForUpdate()
            ->first();

        if (! $coupon) {
            throw ValidationException::withMessages(['coupon_code' => ['كود الخصم غير صحيح لهذه الدورة.']]);
        }

        if (! $coupon->isRedeemable()) {
            throw ValidationException::withMessages(['coupon_code' => ['هذا الكود لم يعد صالحاً (منتهٍ أو معطَّل أو استُنفِد).']]);
        }

        $coupon->increment('redeemed_count');

        $platformRevenue = round((float) $course->platform_revenue, 2);
        $providerAmount = round($course->student_price - $course->platform_revenue, 2);
        $discountAmount = $coupon->isPercent()
            ? round((float) $course->student_price * (float) $coupon->discount_value / 100, 2)
            : round((float) $coupon->discount_value, 2);
        $discountAmount = min($discountAmount, $platformRevenue);
        $platformAmount = round($platformRevenue - $discountAmount, 2);

        return [
            'coupon' => $coupon,
            'discountAmount' => $discountAmount,
            'providerAmount' => $providerAmount,
            'platformAmount' => $platformAmount,
            'amountPaid' => round($providerAmount + $platformAmount, 2),
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
