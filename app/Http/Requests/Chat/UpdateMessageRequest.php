<?php

namespace App\Http\Requests\Chat;

use App\Models\Message;
use App\Rules\NoContactInfo;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('message'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->body)) {
            $this->merge(['body' => trim($this->body)]);
        }
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.Message::MAX_LENGTH, new NoContactInfo],
        ];
    }
}
