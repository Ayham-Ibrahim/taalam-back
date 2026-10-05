<?php

namespace App\Http\Requests\Accountant;

use Illuminate\Foundation\Http\FormRequest;

class CreateAccountantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9]{7,24}$/'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }
}
