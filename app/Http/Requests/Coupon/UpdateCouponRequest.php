<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;
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
            'discount_percent' => ['sometimes', 'numeric', 'min:0.01', 'max:100'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** نفس سقف الإنشاء بالضبط — راجع CreateCouponRequest */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('discount_percent')) {
                return;
            }

            $coupon = $this->route('coupon');
            $package = $coupon?->loadMissing('package')->package;

            if (! $package || $package->platform_margin_percent === null) {
                return;
            }

            $discount = (float) $this->input('discount_percent');

            if ($discount > (float) $package->platform_margin_percent) {
                $validator->errors()->add(
                    'discount_percent',
                    "نسبة الخصم لا يمكن أن تتجاوز هامش المنصة على هذه الباقة ({$package->platform_margin_percent}%).",
                );
            }
        });
    }
}
