<?php

namespace App\Http\Requests\Teacher;

use App\Models\Teacher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateTeacherAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invite', Teacher::class);
    }

    public function rules(): array
    {
        return [
            // فاليديشن صارم على مستوى الباك اند أيضاً (لا يكفي فرضه في الفرونت
            // إند وحده — يمكن تجاوزه بطلب API مباشر): الاسم أحرف فقط (مسافة/
            // شرطة/فاصلة عليا مسموحة بين الكلمات)، والهاتف أرقام فقط مع + اختيارية.
            'name' => ['required', 'string', 'max:150', 'regex:/^[\p{L}\p{M}]+(?:[\s\'-][\p{L}\p{M}]+)*$/u'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?\d{7,24}$/', 'unique:users,phone'],
            'teacher_type' => ['required', Rule::in(['school', 'university', 'training_center'])],
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
