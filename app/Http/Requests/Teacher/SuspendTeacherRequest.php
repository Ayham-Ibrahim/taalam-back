<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class SuspendTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('suspend', $this->route('teacher'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
