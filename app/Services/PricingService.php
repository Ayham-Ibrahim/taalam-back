<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * منطق حساب السعر الوحيد في النظام — لا يُكتب هذا الحساب في أي مكان آخر
 * (PackageApprovalService و CourseApprovalService كلاهما يستدعيان هذه الخدمة).
 *
 * الصيغة محسومة معمارياً — الطالب يدفع سعر المعلم كما حدّده تماماً، ونسبة
 * المنصة تُخصم من هذا السعر (لا تُضاف فوقه)، فيستلم المعلم الصافي فقط:
 *   provider_total   = unit_price × units          (سعر المعلم الإجمالي)
 *   student_price    = provider_total               (ما يدفعه الطالب)
 *   platform_revenue = provider_total × margin/100  (حصة المنصة المخصومة)
 *   provider_net     = provider_total − platform_revenue (صافي المعلم)
 *
 * الحد الأقصى 100% — أي نسبة أعلى تجعل صافي المعلم سالباً.
 */
class PricingService
{
    /**
     * @return array{student_price: float, platform_revenue: float, provider_total: float, provider_net: float}
     */
    public function calculateStudentPrice(float $unitPrice, float $marginPercent, float $units = 1): array
    {
        if ($marginPercent < 0 || $marginPercent > 100) {
            throw ValidationException::withMessages([
                'platform_margin_percent' => ['نسبة المنصة يجب أن تكون بين 0% و 100%'],
            ]);
        }

        $providerTotal = round($unitPrice * $units, 2);
        $platformRevenue = round($providerTotal * $marginPercent / 100, 2);
        $providerNet = round($providerTotal - $platformRevenue, 2);

        return [
            'student_price' => $providerTotal,
            'platform_revenue' => $platformRevenue,
            'provider_total' => $providerTotal,
            'provider_net' => $providerNet,
        ];
    }
}
