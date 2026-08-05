<?php

namespace App\Http\Requests\Complaint;

use App\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resolve', Complaint::class);
    }

    public function rules(): array
    {
        return [
            'resolution_type' => ['required', Rule::in([
                'makeup_session', 'credit', 'no_action', 'warning_issued',
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
