<?php

namespace App\Http\Requests\Taxonomy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxonomyItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->route('type');
        $id = $this->route('id');

        return match ($type) {
            'curricula' => [
                'code' => ['required', 'string', 'max:30', Rule::unique('curricula', 'code')->ignore($id)],
                'name_ar' => ['required', 'string', 'max:100'],
                'name_en' => ['nullable', 'string', 'max:100'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'stages' => [
                'code' => ['required', 'string', 'max:30', Rule::unique('stages', 'code')->ignore($id)],
                'name_ar' => ['required', 'string', 'max:100'],
                'education_type' => ['required', Rule::in(['school', 'university', 'training'])],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'subjects' => [
                'code' => ['required', 'string', 'max:50', Rule::unique('subjects', 'code')->ignore($id)],
                'name_ar' => ['required', 'string', 'max:120'],
                'name_en' => ['nullable', 'string', 'max:120'],
                'education_type' => ['required', Rule::in(['school', 'university', 'training'])],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'universities' => [
                'name_ar' => ['required', 'string', 'max:160'],
                'name_en' => ['nullable', 'string', 'max:160'],
                'country' => ['nullable', 'string', 'max:80'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'majors' => [
                'name_ar' => ['required', 'string', 'max:160'],
                'name_en' => ['nullable', 'string', 'max:160'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'course_fields' => [
                'code' => ['required', 'string', 'max:50', Rule::unique('course_fields', 'code')->ignore($id)],
                'name_ar' => ['required', 'string', 'max:120'],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'languages' => [
                'code' => ['required', 'string', 'max:10', Rule::unique('languages', 'code')->ignore($id)],
                'name_ar' => ['required', 'string', 'max:60'],
                'is_active' => ['nullable', 'boolean'],
            ],
            default => [],
        };
    }

    /**
     * لا يوجد lang/ar في هذا المشروع (APP_LOCALE=en) — بلا messages() صريحة
     * هنا تصل الرسائل بالإنجليزية الكاملة ("The education type field is
     * required.")؛ attributes() وحدها لا تكفي لأنها تستبدل :attribute فقط
     * داخل قالب الرسالة الإنجليزي نفسه ("The نوع التعليم field...") بدل
     * ترجمته كاملاً.
     */
    public function messages(): array
    {
        return [
            'code.required' => 'الرمز مطلوب',
            'code.unique' => 'هذا الرمز مستخدَم بالفعل',
            'name_ar.required' => 'الاسم بالعربية مطلوب',
            'education_type.required' => 'نوع التعليم مطلوب',
            'education_type.in' => 'نوع التعليم غير صالح',
            'country.max' => 'اسم الدولة طويل جداً',
        ];
    }
}
