<?php

namespace App\Http\Requests\Verification;

use Illuminate\Foundation\Http\FormRequest;

class RevokeBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('revoke', $this->route('badgeAward'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
