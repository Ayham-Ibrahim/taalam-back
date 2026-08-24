<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AdminResetStudentPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resetPassword', $this->route('student'));
    }

    public function rules(): array
    {
        return [
            'password' => ['required', Password::defaults()],
        ];
    }

    public function attributes(): array
    {
        return [
            'password' => 'كلمة المرور الجديدة',
        ];
    }
}
