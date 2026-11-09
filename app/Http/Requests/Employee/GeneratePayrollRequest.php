<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

/** توليد كشوف رواتب شهر لكل الموظفين النشطين — الشهر الحالي افتراضياً */
class GeneratePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Employee::class);
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['month' => now()->format('Y-m')]);
    }

    public function rules(): array
    {
        return [
            'month' => [
                'required',
                'date_format:Y-m',
                // لا كشف لشهر لم يبدأ — مقارنة نصية صحيحة ترتيبياً بصيغة YYYY-MM
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_string($value) && $value > now()->format('Y-m')) {
                        $fail('لا يمكن توليد كشوف لشهر لم يبدأ بعد');
                    }
                },
            ],
        ];
    }
}
