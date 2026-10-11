<?php

namespace App\Http\Requests\Package;

use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;

class ApprovePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', Package::class);
    }

    public function rules(): array
    {
        return [
            'platform_margin_percent' => ['required', 'numeric', 'min:0'],
        ];
    }
}
