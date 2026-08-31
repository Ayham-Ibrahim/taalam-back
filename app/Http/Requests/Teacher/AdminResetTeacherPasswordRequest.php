<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AdminResetTeacherPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resetPassword', $this->route('teacher'));
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
