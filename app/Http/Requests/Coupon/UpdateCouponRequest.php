<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('coupon'));
    }

    public function rules(): array
    {
        return [
            'discount_type' => ['sometimes', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['sometimes', 'numeric', 'min:0.01'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** نفس سقف الإنشاء بالضبط — راجع CreateCouponRequest. يأخذ القيمة/النوع الحاليين للكوبون عند عدم إرسالهما (تعديل جزئي) */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('discount_type') && ! $this->filled('discount_value')) {
                return;
            }

            $coupon = $this->route('coupon');
            $package = $coupon?->loadMissing('package')->package;

            if (! $package || $package->platform_margin_percent === null) {
                return;
            }

            $type = $this->input('discount_type', $coupon->discount_type);
            $value = (float) $this->input('discount_value', $coupon->discount_value);

            if ($type === 'percent') {
                if ($value > (float) $package->platform_margin_percent) {
                    $validator->errors()->add(
                        'discount_value',
                        "نسبة الخصم لا يمكن أن تتجاوز هامش المنصة على هذه الباقة ({$package->platform_margin_percent}%).",
                    );
                }

                return;
            }

            if ($value > (float) $package->platform_revenue) {
                $validator->errors()->add(
                    'discount_value',
                    "قيمة الخصم لا يمكن أن تتجاوز حصة المنصة من هذه الباقة ({$package->platform_revenue} {$package->currency}).",
                );
            }
        });
    }
}
