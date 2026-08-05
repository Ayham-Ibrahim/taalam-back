<?php

namespace App\Http\Requests\Package;

use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;

class RejectPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', Package::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
