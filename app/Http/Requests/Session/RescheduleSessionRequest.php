<?php

namespace App\Http\Requests\Session;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reschedule', $this->route('session'));
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
