<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** بيانات الحساب الأساسية لأي دور — المؤهلات الأكاديمية للطالب تُعدَّل عبر StudentController::update بشكل منفصل */
class UpdateMyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:25', Rule::unique('users', 'phone')->ignore($this->user()->id)],
            'whatsapp' => ['nullable', 'string', 'max:25'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            // اختيار المستخدم اليدوي للمنطقة الزمنية من الإعدادات — يوقف التحديث التلقائي من المتصفح (SyncTimezoneRequest)
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            'timezone_auto' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'phone' => 'رقم الهاتف',
            'whatsapp' => 'واتساب',
            'gender' => 'الجنس',
            'timezone' => 'المنطقة الزمنية',
        ];
    }
}
