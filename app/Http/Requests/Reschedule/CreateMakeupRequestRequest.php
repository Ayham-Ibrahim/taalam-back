<?php

namespace App\Http\Requests\Reschedule;

use App\Models\RescheduleRequest;
use Illuminate\Foundation\Http\FormRequest;

class CreateMakeupRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [RescheduleRequest::class, $this->route('session')]);
    }

    public function rules(): array
    {
        return [
            'proposed_scheduled_at' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'proposed_scheduled_at.required' => 'يرجى تحديد موعد الجلسة التعويضية',
            'proposed_scheduled_at.date' => 'الموعد غير صالح',
            'proposed_scheduled_at.after' => 'يجب أن يكون موعد الجلسة التعويضية في المستقبل',
            'reason.max' => 'الملاحظة طويلة جداً',
        ];
    }
}
