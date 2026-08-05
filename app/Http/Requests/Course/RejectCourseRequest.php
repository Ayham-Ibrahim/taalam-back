<?php

namespace App\Http\Requests\Course;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;

class RejectCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', Course::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
