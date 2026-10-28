<?php

namespace App\Http\Requests\Coupon\Concerns;

use App\Models\Course;
use App\Models\Package;
use Illuminate\Validation\Validator;

/**
 * نفس سقف CreateCouponRequest لكن على كل باقة/دورة مشمولة: الخصم (نسبةً أو
 * مبلغاً) لا يتجاوز هامش المنصة على أي واحدة منها، فيبقى مستحق المعلم/المركز
 * غير متأثر دائماً.
 */
trait ValidatesMultiPackageCouponCap
{
    /**
     * $attached*Ids: عناصر مربوطة بالكوبون مسبقاً (عند التعديل) — تُعفى من شرط
     * status=active كي لا يمنع اكتمال عددها (full) أو تعطيلها تعديلَ الكوبون نفسه.
     */
    protected function validateCap(
        Validator $validator,
        array $packageIds,
        array $courseIds,
        ?string $type,
        ?float $value,
        array $attachedPackageIds = [],
        array $attachedCourseIds = [],
    ): void {
        $columns = ['id', 'title', 'status', 'platform_margin_percent', 'platform_revenue', 'currency'];

        $items = [
            ['الباقة', Package::whereIn('id', $packageIds)->get($columns), $attachedPackageIds],
            ['الدورة', Course::whereIn('id', $courseIds)->get($columns), $attachedCourseIds],
        ];

        foreach ($items as [$label, $models, $attachedIds]) {
            foreach ($models as $model) {
                $mustBeActive = ! in_array($model->id, $attachedIds, true);

                if (($mustBeActive && $model->status !== 'active') || $model->platform_margin_percent === null) {
                    $validator->errors()->add('package_ids', "{$label} «{$model->title}» غير معتمدة — لا يمكن إضافتها للكوبون.");

                    continue;
                }

                if ($type === null || $value === null) {
                    continue;
                }

                if ($type === 'percent' && $value > (float) $model->platform_margin_percent) {
                    $validator->errors()->add(
                        'discount_value',
                        "نسبة الخصم تتجاوز هامش المنصة على {$label} «{$model->title}» ({$model->platform_margin_percent}%).",
                    );
                }

                if ($type === 'fixed' && $value > (float) $model->platform_revenue) {
                    $validator->errors()->add(
                        'discount_value',
                        "قيمة الخصم تتجاوز حصة المنصة من {$label} «{$model->title}» ({$model->platform_revenue} {$model->currency}).",
                    );
                }
            }
        }
    }
}
