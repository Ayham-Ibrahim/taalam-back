<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchTeachersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'teacher_type' => ['nullable', Rule::in(['school', 'university', 'training_center'])],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'curriculum_id' => ['nullable', 'integer', 'exists:curricula,id'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'city' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
