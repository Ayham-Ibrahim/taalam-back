<?php

namespace App\Http\Requests\SupportPackage;

use App\Models\SupportPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** نفس حقول CreateSupportPackageRequest بالضبط — تعديل أدمن فقط، ويُمنَع عند وجود عمليات شراء قائمة (راجع SupportPackageService::update) */
class UpdateSupportPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', SupportPackage::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            'description' => is_string($this->description) ? trim($this->description) : $this->description,
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'tier' => ['required', Rule::in(['basic', 'premium'])],
            'subject_ids' => ['required', 'array', 'min:1', 'max:10'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
            'price' => ['required', 'numeric', 'min:1', 'max:100000'],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'يرجى إدخال اسم الباقة',
            'tier.required' => 'يرجى اختيار نوع الباقة',
            'tier.in' => 'نوع الباقة غير صالح',
            'subject_ids.required' => 'يرجى اختيار مادة واحدة على الأقل',
            'subject_ids.min' => 'يرجى اختيار مادة واحدة على الأقل',
            'subject_ids.max' => 'لا يمكن اختيار أكثر من 10 مواد لنفس الباقة',
            'subject_ids.*.exists' => 'إحدى المواد المختارة غير موجودة',
            'price.required' => 'يرجى إدخال سعر الباقة',
            'price.numeric' => 'السعر يجب أن يكون رقماً',
            'price.min' => 'السعر يجب أن يكون أكبر من صفر',
            'price.max' => 'السعر كبير جداً',
            'description.required' => 'الوصف حقل إلزامي ولا يمكن أن يكون فارغاً',
            'description.max' => 'الوصف يجب ألا يتجاوز 2000 حرف',
        ];
    }
}
