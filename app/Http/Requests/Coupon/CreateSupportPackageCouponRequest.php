<?php

namespace App\Http\Requests\Coupon;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * يوازي CreateCouponRequest تماماً لكن لباقة دعم أكاديمي — لا هامش منصة لحمايته
 * هنا (platform_revenue=0 دائماً)، فالسقف هو سعر الباقة نفسه مباشرة بدل هامشها.
 */
class CreateSupportPackageCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Coupon::class, $this->route('supportPackage')]);
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $supportPackage = $this->route('supportPackage');

            if (! $supportPackage || $supportPackage->status !== 'active') {
                $validator->errors()->add('support_package', 'لا يمكن إضافة كوبون لباقة لم تُعتمَد بعد.');

                return;
            }

            if (! $this->filled('discount_type') || ! $this->filled('discount_value')) {
                return;
            }

            $value = (float) $this->input('discount_value');

            if ($this->input('discount_type') === 'percent') {
                if ($value > 100) {
                    $validator->errors()->add('discount_value', 'نسبة الخصم لا يمكن أن تتجاوز 100%.');
                }

                return;
            }

            if ($value > (float) $supportPackage->student_price) {
                $validator->errors()->add(
                    'discount_value',
                    "قيمة الخصم لا يمكن أن تتجاوز سعر الباقة ({$supportPackage->student_price} {$supportPackage->currency}).",
                );
            }
        });
    }
}
