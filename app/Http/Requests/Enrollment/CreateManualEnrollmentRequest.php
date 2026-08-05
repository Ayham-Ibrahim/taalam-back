<?php

namespace App\Http\Requests\Enrollment;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;

class CreateManualEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createManual', Enrollment::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
