<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** الأدمن يعدّل بيانات حساب الطالب الأساسية (الاسم/البريد/الهاتف) — الملف الأكاديمي له مساره المنفصل */
class AdminUpdateStudentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateAccount', $this->route('student'));
    }

    public function rules(): array
    {
        $user = $this->route('student')->user;

        return [
            'name' => ['required', 'string', 'max:150'],
            // حساب الأخ بلا بريد — يدخل ببريد الأساسي (SiblingAccountService)، فلا يُعطى بريداً خاصاً
            'email' => $user->primary_user_id
                ? ['prohibited']
                : ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25', Rule::unique('users', 'phone')->ignore($user->id)],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الهاتف',
        ];
    }
}
