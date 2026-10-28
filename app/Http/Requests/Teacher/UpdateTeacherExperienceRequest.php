<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeacherExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('experience')->teacher);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'company' => ['nullable', 'string', 'max:200'],
            'period' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'المسمى',
            'company' => 'الشركة أو الجهة أو المؤسسة',
            'period' => 'الفترة',
            'location' => 'الموقع',
            'description' => 'الوصف',
        ];
    }
}
