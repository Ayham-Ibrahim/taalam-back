<?php

namespace App\Http\Requests\AcademicSupporter;

use App\Models\AcademicSupporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CreateAcademicSupporterAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AcademicSupporter::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'regex:/^[\p{L}\p{M}]+(?:[\s\'-][\p{L}\p{M}]+)*$/u'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?\d{7,24}$/', 'unique:users,phone'],
            'password' => ['required', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'الاسم يجب أن يحتوي على أحرف فقط من دون أرقام أو رموز',
            'phone.regex' => 'أدخل رقم هاتف صحيحًا باستخدام الأرقام فقط',
        ];
    }
}
