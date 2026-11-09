<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** بونص أو خصم لموظف في شهر — بونص للشهر الحالي افتراضياً */
class StoreEmployeeAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Employee::class);
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['month' => now()->format('Y-m'), 'type' => EmployeeAdjustment::TYPE_BONUS]);
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'type' => ['required', Rule::in([EmployeeAdjustment::TYPE_BONUS, EmployeeAdjustment::TYPE_DEDUCTION])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'month' => 'الشهر',
            'type' => 'النوع',
            'amount' => 'المبلغ',
            'reason' => 'السبب',
        ];
    }
}
