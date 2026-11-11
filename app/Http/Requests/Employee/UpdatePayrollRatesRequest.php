<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

/** أسعار صرف شهر: rates.{USD,EGP} = قيمة الوحدة بالدرهم (1 USD = 3.6725) */
class UpdatePayrollRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Employee::class);
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            ...self::rateRules(required: true),
        ];
    }

    /** قواعد rates.* المشتركة مع توليد الكشوف — عملة واحدة على الأقل */
    public static function rateRules(bool $required): array
    {
        $rules = ['rates' => [$required ? 'required' : 'nullable', 'array']];

        foreach (Employee::CURRENCIES as $currency) {
            if ($currency !== Employee::BASE_CURRENCY) {
                $rules["rates.{$currency}"] = ['nullable', 'numeric', 'gt:0', 'max:99999999'];
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'rates.USD' => 'سعر صرف الدولار',
            'rates.EGP' => 'سعر صرف الجنيه',
        ];
    }
}
