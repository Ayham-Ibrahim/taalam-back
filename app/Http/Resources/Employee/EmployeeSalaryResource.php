<?php

namespace App\Http\Resources\Employee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeSalaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'employeeName' => $this->whenLoaded('employee', fn () => $this->employee->name),
            'jobTitle' => $this->whenLoaded('employee', fn () => $this->employee->job_title),
            'month' => $this->month,
            'baseSalary' => (float) $this->base_salary,
            'bonusesTotal' => (float) $this->bonuses_total,
            'deductionsTotal' => (float) $this->deductions_total,
            'netAmount' => (float) $this->net_amount,
            'currency' => $this->currency,
            'exchangeRate' => (float) $this->exchange_rate,
            'baseSalaryAed' => (float) $this->base_salary_aed,
            'bonusesTotalAed' => (float) $this->bonuses_total_aed,
            'deductionsTotalAed' => (float) $this->deductions_total_aed,
            'netAmountAed' => (float) $this->net_amount_aed,
            'status' => $this->status,
            'paidAt' => optional($this->paid_at)->toDateTimeString(),
            'paidBy' => $this->whenLoaded('payer', fn () => $this->payer?->name),
            'notes' => $this->notes,
        ];
    }
}
