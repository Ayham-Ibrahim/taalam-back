<?php

namespace App\Services;

/**
 * منطق حساب السعر الوحيد في النظام — لا يُكتب هذا الحساب في أي مكان آخر
 * (PackageApprovalService و CourseApprovalService كلاهما يستدعيان هذه الخدمة).
 *
 * الصيغة محسومة معمارياً — سعر بالوحدة (بالساعة دائماً للباقات، أو للدورة
 * حسب pricing_mode) يُضرب بعدد الوحدات (units) قبل هامش المنصة:
 *   provider_total   = unit_price × units
 *   student_price    = provider_total × (1 + margin/100)
 *   platform_revenue = student_price - provider_total
 */
class PricingService
{
    /**
     * @return array{student_price: float, platform_revenue: float, provider_total: float}
     */
    public function calculateStudentPrice(float $unitPrice, float $marginPercent, float $units = 1): array
    {
        $providerTotal = round($unitPrice * $units, 2);
        $studentPrice = round($providerTotal * (1 + $marginPercent / 100), 2);
        $platformRevenue = round($studentPrice - $providerTotal, 2);

        return [
            'student_price' => $studentPrice,
            'platform_revenue' => $platformRevenue,
            'provider_total' => $providerTotal,
        ];
    }
}
