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
            // منطقة متصفح المعلم لحظة قبول الدعوة — تطغى على ما اختاره الأدمن عند الدعوة (مكانه الفعلي)
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all_with_bc'],
        ];
    }

    public function attributes(): array
    {
        return [
            'accept_policy' => 'الموافقة على سياسة المعلم',
        ];
    }
}
