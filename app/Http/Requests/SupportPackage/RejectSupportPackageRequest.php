<?php

namespace App\Http\Requests\SupportPackage;

use App\Models\SupportPackage;
use Illuminate\Foundation\Http\FormRequest;

class RejectSupportPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', SupportPackage::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
