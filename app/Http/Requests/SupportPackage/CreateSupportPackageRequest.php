<?php

namespace App\Http\Requests\SupportPackage;

use App\Models\SupportPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSupportPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SupportPackage::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => is_string($this->description) ? trim($this->description) : $this->description,
        ]);
    }

    public function rules(): array
    {
        return [
            'tier' => ['required', Rule::in(['basic', 'premium'])],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'tier.required' => 'يرجى اختيار نوع الباقة',
            'tier.in' => 'نوع الباقة غير صالح',
            'subject_id.required' => 'يرجى اختيار المادة',
            'subject_id.exists' => 'المادة المختارة غير موجودة',
            'description.required' => 'الوصف حقل إلزامي ولا يمكن أن يكون فارغاً',
            'description.max' => 'الوصف يجب ألا يتجاوز 2000 حرف',
        ];
    }
}
