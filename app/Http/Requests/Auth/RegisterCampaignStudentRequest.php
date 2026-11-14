<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** تسجيل من رابط الحملة — الاسم والبريد والهاتف وكلمة المرور فقط */
class RegisterCampaignStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->where(fn ($q) => $q->where('account_type', '!=', 'guest'))],
            'phone' => ['required', 'string', 'max:25', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'timezone' => ['nullable', 'string', 'max:64'],
            'accept_policy' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الهاتف',
            'password' => 'كلمة المرور',
            'accept_policy' => 'الموافقة على سياسة الطالب',
        ];
    }
}
