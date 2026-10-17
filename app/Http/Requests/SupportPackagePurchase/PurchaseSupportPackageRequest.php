<?php

namespace App\Http\Requests\SupportPackagePurchase;

use App\Models\SupportPackagePurchase;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseSupportPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SupportPackagePurchase::class);
    }

    public function rules(): array
    {
        return [
            'coupon_code' => ['nullable', 'string', 'max:30'],
        ];
    }
}
