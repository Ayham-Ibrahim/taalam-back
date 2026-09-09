<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class AddTeacherExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('teacher'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'period' => ['required', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return ['title' => 'المسمى', 'period' => 'الفترة'];
    }
}
