<?php

namespace App\Http\Requests\AcademicSupporter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AdminResetAcademicSupporterPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resetPassword', $this->route('academicSupporter'));
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
