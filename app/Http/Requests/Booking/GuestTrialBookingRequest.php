<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** نفس شكل RequestIndividualBookingRequest، زائداً هوية الزائر ورمز التحقق ومنطقته الزمنية */
class GuestTrialBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:150'],
            'code' => ['required', 'digits:6'],
            'name' => ['required', 'string', 'max:150'],
            // منطقة متصفح الزائر — بها تُفسَّر المواعيد المختارة وتُعرض في رسائل البريد
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'slots' => ['required', 'array', 'min:1'],
            'slots.*.date' => ['required', 'date'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'البريد الإلكتروني',
            'code' => 'رمز التحقق',
            'name' => 'الاسم',
            'timezone' => 'المنطقة الزمنية',
            'slots' => 'المواعيد',
        ];
    }
}
