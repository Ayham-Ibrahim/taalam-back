<?php

namespace App\Http\Requests\Package;

use App\Rules\CapacityMatchesFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('package'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'session_format' => ['required', Rule::in(['individual', 'group'])],
            'capacity' => ['required', 'integer', 'min:1', 'max:100', new CapacityMatchesFormat],
            'sessions_count' => ['required', 'integer', 'min:1', 'max:200'],
            'teacher_price' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'curriculum_ids' => ['nullable', 'array', 'max:20'],
            'curriculum_ids.*' => ['integer', 'exists:curricula,id'],
            'stage_ids' => ['nullable', 'array', 'max:20'],
            'stage_ids.*' => ['integer', 'exists:stages,id'],
            'schedules' => ['nullable', 'array', 'min:1', 'max:200'],
            'schedules.*.date' => ['nullable', 'required_if:session_format,group', 'date', 'after_or_equal:today'],
            'schedules.*.start_time' => ['nullable', 'required_if:session_format,group', 'date_format:H:i'],
            'schedules.*.day_of_week' => ['nullable', 'required_if:session_format,individual', 'integer', 'min:0', 'max:6'],
        ];
    }
}
