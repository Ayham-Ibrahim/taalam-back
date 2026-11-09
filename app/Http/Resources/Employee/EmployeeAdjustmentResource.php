<?php

namespace App\Http\Resources\Employee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employeeId' => $this->employee_id,
            'month' => $this->month,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'reason' => $this->reason,
            'createdBy' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'createdAt' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
