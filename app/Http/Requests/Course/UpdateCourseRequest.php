<?php

namespace App\Http\Requests\Course;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('course'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'course_field_id' => ['required', 'integer', 'exists:course_fields,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'level' => ['nullable', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'delivery_mode' => ['nullable', Rule::in(['online', 'onsite', 'hybrid', 'recorded'])],
            'location' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_sessions' => ['required', 'integer', 'min:1', 'max:500'],
            'session_duration_min' => ['nullable', 'integer', 'min:15', 'max:240'],
            'total_hours' => ['required_if:pricing_mode,hourly', 'nullable', 'integer', 'min:1', 'max:2000'],
            'max_seats' => ['required', 'integer', 'min:1', 'max:1000'],
            'provider_price' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'pricing_mode' => ['required', Rule::in(['total', 'hourly'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'has_certificate' => ['required', 'boolean'],
            'certificate_type' => ['nullable', 'string', 'max:120'],
            'certificate_issuer' => ['nullable', 'string', 'max:160'],
            'certificate_requirements' => ['nullable', 'string', 'max:2000'],
            'requires_laptop' => ['required', 'boolean'],
            'materials_included' => ['required', 'boolean'],
            'has_practical_exercises' => ['required', 'boolean'],
            'sessions_recorded' => ['required', 'boolean'],
            'prerequisites' => ['nullable', 'string', 'max:2000'],
            'cancellation_policy' => ['nullable', 'string', 'max:2000'],
            'curriculum_ids' => ['nullable', 'array', 'max:20'],
            'curriculum_ids.*' => ['integer', 'exists:curricula,id'],
            'schedules' => ['nullable', 'array', 'max:50'],
            'schedules.*.day_of_week' => ['required_with:schedules', 'integer', 'min:0', 'max:6'],
            'schedules.*.start_time' => ['required_with:schedules', 'date_format:H:i'],
            'schedules.*.end_time' => ['required_with:schedules', 'date_format:H:i'],
        ];
    }
}
