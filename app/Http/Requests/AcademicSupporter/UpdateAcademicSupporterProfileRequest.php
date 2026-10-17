<?php

namespace App\Http\Requests\AcademicSupporter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicSupporterProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('academicSupporter'));
    }

    public function rules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:500'],
            'qualification' => ['nullable', Rule::in(['bachelor', 'master', 'phd', 'professional_cert', 'diploma'])],
            'experience_years' => ['nullable', Rule::in(['under_1', '1_3', '3_5', 'over_5'])],
            'subject_ids' => ['nullable', 'array', 'max:30'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
            'language_ids' => ['nullable', 'array', 'max:20'],
            'language_ids.*' => ['integer', 'exists:languages,id'],
        ];
    }
}
