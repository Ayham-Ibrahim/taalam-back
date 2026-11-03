<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_policy' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return [
            'accept_policy' => 'الموافقة على سياسة المعلم',
        ];
    }
}
