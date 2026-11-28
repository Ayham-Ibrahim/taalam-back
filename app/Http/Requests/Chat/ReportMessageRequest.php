<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class ReportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('report', $this->route('message'));
    }

    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
