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
        // أرقام فقط (٧–٢٤ خانة) مع بادئة + اختيارية للرقم الدولي — نفس نمط
        // CreateTeacherAccountRequest، فلا يُقبل أي حرف أو رمز.
        $phoneFormat = ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9]{7,24}$/'];

        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => [...$phoneFormat, Rule::unique('users', 'phone')->ignore($this->user()->id)],
            'whatsapp' => $phoneFormat,
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

    public function messages(): array
    {
        return [
            'phone.regex' => 'رقم الهاتف يجب أن يحتوي أرقامًا فقط (من ٧ إلى ٢٤ رقمًا)، ويمكن أن يبدأ بعلامة + للرقم الدولي.',
            'whatsapp.regex' => 'رقم الواتساب يجب أن يحتوي أرقامًا فقط (من ٧ إلى ٢٤ رقمًا)، ويمكن أن يبدأ بعلامة + للرقم الدولي.',
        ];
    }
}
