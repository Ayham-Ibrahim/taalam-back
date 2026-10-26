<?php

namespace App\Http\Requests\Coupon;

use App\Http\Requests\Coupon\Concerns\ValidatesMultiPackageCouponCap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMultiPackageCouponRequest extends FormRequest
{
    use ValidatesMultiPackageCouponCap;

    public function authorize(): bool
    {
        return $this->user()->isAdmin() && $this->route('coupon')->isMultiPackage();
    }

    public function rules(): array
    {
        return [
            'package_ids' => ['sometimes', 'nullable', 'array'],
            'package_ids.*' => ['integer', 'distinct', Rule::exists('packages', 'id')],
            'course_ids' => ['sometimes', 'nullable', 'array'],
            'course_ids.*' => ['integer', 'distinct', Rule::exists('courses', 'id')],
            'discount_type' => ['sometimes', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['sometimes', 'numeric', 'min:0.01'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** يأخذ القيم الحالية للكوبون عند عدم إرسالها (تعديل جزئي) — الخصم يُفحص على كل العناصر بعد التعديل */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $discountChanged = $this->filled('discount_type') || $this->filled('discount_value');
            $itemsChanged = $this->has('package_ids') || $this->has('course_ids');

            if (! $discountChanged && ! $itemsChanged) {
                return;
            }

            $coupon = $this->route('coupon');
            $attachedPackageIds = $coupon->packages()->pluck('packages.id')->all();
            $attachedCourseIds = $coupon->courses()->pluck('courses.id')->all();

            $packageIds = array_map('intval', $this->has('package_ids') ? ($this->input('package_ids') ?? []) : $attachedPackageIds);
            $courseIds = array_map('intval', $this->has('course_ids') ? ($this->input('course_ids') ?? []) : $attachedCourseIds);

            if (count($packageIds) + count($courseIds) === 0) {
                $validator->errors()->add('package_ids', 'اختر باقة أو دورة واحدة على الأقل.');

                return;
            }

            $this->validateCap(
                $validator,
                $packageIds,
                $courseIds,
                $this->input('discount_type', $coupon->discount_type),
                (float) $this->input('discount_value', $coupon->discount_value),
                $attachedPackageIds,
                $attachedCourseIds,
            );
        });
    }
}
