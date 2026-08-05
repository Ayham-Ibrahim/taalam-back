<?php

namespace App\Http\Requests\Verification;

use App\Models\BadgeAward;
use Illuminate\Foundation\Http\FormRequest;

class GrantBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('grant', BadgeAward::class);
    }

    public function rules(): array
    {
        return [
            'badge_id' => ['required', 'integer', 'exists:badges,id'],
        ];
    }
}
