<?php

namespace App\Http\Requests\Reschedule;

use App\Models\RescheduleRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectRescheduleRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', RescheduleRequest::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
