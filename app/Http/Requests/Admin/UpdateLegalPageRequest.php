<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLegalPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.title_ar' => ['required', 'string', 'max:200'],
            'sections.*.title_en' => ['required', 'string', 'max:200'],
            'sections.*.body_ar' => ['required', 'string', 'max:20000'],
            'sections.*.body_en' => ['required', 'string', 'max:20000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sections' => 'الأقسام',
        ];
    }
}
