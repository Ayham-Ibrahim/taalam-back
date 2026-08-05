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
                'sort_order' => ['nullable', 'integer'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'stages' => [
                'code' => ['required', 'string', 'max:30', Rule::unique('stages', 'code')->ignore($id)],
                'name_ar' => ['required', 'string', 'max:100'],
                'education_type' => ['required', Rule::in(['school', 'university', 'training'])],
                'sort_order' => ['nullable', 'integer'],
                'is_active' => ['nullable', 'boolean'],
            ],
            'subjects' => [
                'code' => ['required', 'string', 'max:50', Rule::unique('subjects', 'code')->ignore($id)],
                'name_ar' => ['required', 'string', 'max:120'],
                'name_en' => ['nullable', 'string', 'max:120'],
                'education_type' => ['required', Rule::in(['school', 'university', 'training'])],
                'sort_order' => ['nullable', 'integer'],
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
                'sort_order' => ['nullable', 'integer'],
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
}
