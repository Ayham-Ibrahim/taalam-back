<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Setting::class);
    }

    public function rules(): array
    {
        return [
            // القيمة تُخزَّن دائماً كنص (SettingsService::set يطبّق (string) $value)، لكن الأنواع
            // integer/decimal/boolean قد تصل كقيم JSON غير نصية — لذا نقبل أي نوع بسيط ونرفض
            // فقط المصفوفات (تمنع "Array to string conversion" وتلف قيمة الإعداد) مع سقف طول.
            'value' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_array($value)) {
                        $fail('قيمة الإعداد يجب أن تكون نصاً أو رقماً أو قيمة منطقية، وليست مصفوفة.');

                        return;
                    }

                    if (mb_strlen((string) $value) > 5000) {
                        $fail('قيمة الإعداد طويلة جداً (الحد الأقصى 5000 حرف).');

                        return;
                    }

                    if ($this->route('key') === 'student_no_show_teacher_compensation_percent'
                        && (! is_numeric($value) || $value < 0 || $value > 100)) {
                        $fail('النسبة يجب أن تكون رقماً بين 0 و100.');

                        return;
                    }

                    if ($this->route('key') === 'contact_email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $fail('يرجى إدخال بريد إلكتروني صحيح.');
                    }
                },
            ],
        ];
    }
}
