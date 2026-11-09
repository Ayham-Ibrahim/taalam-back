<?php

namespace App\Http\Requests\Employee;

/** نفس قواعد الإنشاء لكن كل الحقول اختيارية (تعديل جزئي) */
class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function rules(): array
    {
        return collect(parent::rules())
            ->map(fn (array $rules) => ['sometimes', ...array_values(array_diff($rules, ['required', 'sometimes']))])
            ->all();
    }
}
