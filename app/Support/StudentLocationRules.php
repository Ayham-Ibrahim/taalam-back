<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * قواعد دولة/ولاية الطالب المشتركة بين كل نماذجه (التسجيل، الحملة، الملف، الأخ، الأدمن).
 * الولاية إن أُرسلت مع دولة يجب أن تتبعها؛ وبلا دولة تُشتق الدولة منها (Student::saving).
 */
class StudentLocationRules
{
    public static function rules(mixed $countryId, bool $requireCountry = false): array
    {
        $region = Rule::exists('regions', 'id');

        if (filled($countryId)) {
            $region->where('country_id', $countryId);
        }

        return [
            'country_id' => [$requireCountry ? 'required' : 'nullable', 'exists:countries,id'],
            'region_id' => ['nullable', $region],
        ];
    }

    public static function messages(): array
    {
        return [
            'country_id.required' => 'اختر الدولة',
            'country_id.exists' => 'الدولة غير موجودة',
            'region_id.exists' => 'الولاية غير موجودة أو لا تتبع الدولة المختارة',
        ];
    }
}
