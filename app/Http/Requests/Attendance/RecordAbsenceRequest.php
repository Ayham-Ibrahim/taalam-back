<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class RecordAbsenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin() || $this->user()->isTeacher();
    }

    public function rules(): array
    {
        return [
            'notified_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
