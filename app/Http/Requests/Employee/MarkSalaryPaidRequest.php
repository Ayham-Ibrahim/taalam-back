<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class MarkSalaryPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Employee::class);
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
