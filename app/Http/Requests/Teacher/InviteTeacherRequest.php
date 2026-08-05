<?php

namespace App\Http\Requests\Teacher;

use App\Models\Teacher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invite', Teacher::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'unique:users,phone'],
            'teacher_type' => ['required', Rule::in(['school', 'university', 'training_center'])],
        ];
    }
}
