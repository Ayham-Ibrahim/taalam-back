<?php

namespace App\Http\Requests\Reschedule;

use App\Models\RescheduleRequest;
use Illuminate\Foundation\Http\FormRequest;

class CreateRescheduleRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [RescheduleRequest::class, $this->route('session')]);
    }

    public function rules(): array
    {
        return [
            'proposed_scheduled_at' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
