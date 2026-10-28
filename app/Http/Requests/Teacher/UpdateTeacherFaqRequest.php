<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeacherFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('faq')->teacher);
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:2000'],
        ];
    }
}
