<?php

namespace App\Http\Requests\Payout;

use App\Models\Payout;
use Illuminate\Foundation\Http\FormRequest;

class GeneratePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('generate', Payout::class);
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ];
    }
}
