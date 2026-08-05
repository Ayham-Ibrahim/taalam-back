<?php

namespace App\Http\Requests\Complaint;

use App\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FileComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Complaint::class);
    }

    public function rules(): array
    {
        return [
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'class_session_id' => ['nullable', 'integer', 'exists:class_sessions,id'],
            'category' => ['required', Rule::in([
                'session_quality', 'teacher_no_show', 'technical_issue',
                'payment_issue', 'schedule_issue', 'other',
            ])],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }
}
