<?php

namespace App\Http\Requests\Reschedule;

use App\Models\RescheduleRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveRescheduleRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', RescheduleRequest::class);
    }

    public function rules(): array
    {
        return [
            'alternative_scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
