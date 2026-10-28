<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // نفس قيود CreateTeacherAccountRequest (الاسم أحرف فقط، الهاتف أرقام فقط) —
            // اسم المعلم يظهر علناً بملفه الشخصي، فنفس المعيار المفروض حين ينشئه الأدمن.
            'name' => ['required', 'string', 'max:150', 'regex:/^[\p{L}\p{M}]+(?:[\s\'-][\p{L}\p{M}]+)*$/u'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?\d{7,24}$/', 'unique:users,phone'],
            'teacher_type' => ['required', Rule::in(['school', 'university', 'training_center'])],
            // confirmed إلزامية هنا (بعكس إنشاء الأدمن للحساب) — المعلم هو من يختار
            // كلمة مروره بنفسه، فلازم تأكيدها لتفادي خطأ كتابة لا يكتشفه إلا لاحقاً.
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'الاسم يجب أن يحتوي على أحرف فقط من دون أرقام أو رموز',
            'phone.regex' => 'أدخل رقم هاتف صحيحًا باستخدام الأرقام فقط',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الهاتف',
            'password' => 'كلمة المرور',
            'teacher_type' => 'نوع الحساب',
        ];
    }
}
