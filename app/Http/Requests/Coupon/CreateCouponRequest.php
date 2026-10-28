<?php

namespace App\Http\Requests\Coupon;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Coupon::class, $this->route('package')]);
    }

    public function rules(): array
    {
        return [
            // اختياري — يُولَّد تلقائياً إن تُرك فارغاً (BookingService uses the same Str::random convention)
            'code' => ['nullable', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * السقف الفعلي: لا يمكن لخصم الكوبون (نسبةً أو مبلغاً) أن يتجاوز هامش
     * المنصة على هذه الباقة — هكذا يُقتطع الخصم من حصة المنصة فقط ولا يمس
     * مستحق المعلم أبداً (راجع تعليق migration إنشاء جدول coupons للتفصيل).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $package = $this->route('package');

            if (! $package) {
                return;
            }

            if ($package->status !== 'active' || $package->platform_margin_percent === null) {
                $validator->errors()->add('package', 'لا يمكن إضافة كوبون لباقة لم تُعتمَد بعد.');

                return;
            }

            if (! $this->filled('discount_type') || ! $this->filled('discount_value')) {
                return;
            }

            $value = (float) $this->input('discount_value');

            if ($this->input('discount_type') === 'percent') {
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
