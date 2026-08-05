<?php

namespace App\Http\Requests\Package;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AvailabilitySlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('teacher'));
    }

    public function rules(): array
    {
        return [
            'day_of_week' => [
                'required', 'integer', 'min:0', 'max:6',
                Rule::unique('availability_slots')->where('teacher_id', $this->route('teacher')->id),
            ],
        ];
    }
}
