<?php

namespace App\Http\Resources\Employee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'jobTitle' => $this->job_title,
            'phone' => $this->phone,
            'email' => $this->email,
            'baseSalary' => (float) $this->base_salary,
            'currency' => $this->currency,
            'hiredAt' => optional($this->hired_at)->toDateString(),
            'isActive' => $this->is_active,
            'notes' => $this->notes,
            'salaries' => EmployeeSalaryResource::collection($this->whenLoaded('salaries')),
            'adjustments' => EmployeeAdjustmentResource::collection($this->whenLoaded('adjustments')),
        ];
    }
}
