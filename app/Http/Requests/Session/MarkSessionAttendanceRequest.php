<?php

namespace App\Http\Requests\Session;

use Illuminate\Foundation\Http\FormRequest;

class MarkSessionAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('markAttendance', $this->route('session'));
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'in:teacher,student'],
            'student_id' => ['required_if:role,student', 'nullable', 'integer'],
        ];
    }
}
