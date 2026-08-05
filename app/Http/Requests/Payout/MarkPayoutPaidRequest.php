<?php

namespace App\Http\Requests\Payout;

use App\Models\Payout;
use Illuminate\Foundation\Http\FormRequest;

class MarkPayoutPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('markPaid', Payout::class);
    }

    public function rules(): array
    {
        return [
            'transfer_reference' => ['required', 'string', 'max:120'],
        ];
    }
}
