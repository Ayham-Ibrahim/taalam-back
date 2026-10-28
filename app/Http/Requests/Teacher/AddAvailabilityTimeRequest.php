<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class AddAvailabilityTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('teacher'));
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }

    /** يرفض تداخل النافذة الجديدة مع نافذة قائمة في نفس اليوم */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $overlaps = $this->route('teacher')->availabilityTimes()
                ->where('day_of_week', $this->integer('day_of_week'))
                ->where('start_time', '<', $this->input('end_time'))
                ->where('end_time', '>', $this->input('start_time'))
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('start_time', 'هذه النافذة تتداخل مع وقت توفّر مضاف مسبقاً في نفس اليوم');
            }
        });
    }

    public function messages(): array
    {
        return [
            'end_time.after' => 'وقت النهاية يجب أن يكون بعد وقت البداية',
        ];
    }
}
