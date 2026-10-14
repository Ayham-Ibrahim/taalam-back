<?php

namespace App\Http\Requests\Reschedule;

use App\Models\RescheduleRequest;
use Illuminate\Foundation\Http\FormRequest;

class CreateRescheduleRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [RescheduleRequest::class, $this->route('session')]);
    }

    public function rules(): array
    {
        return [
            // proposed_scheduled_at فارغة = "تبليغ غياب" بلا موعد بديل مقترح — السبب
            // يصبح إلزامياً عندها (RescheduleService::request يفرض هذا أيضاً دفاعياً).
            'proposed_scheduled_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['required_without:proposed_scheduled_at', 'nullable', 'string', 'max:1000'],
        ];
    }
}
