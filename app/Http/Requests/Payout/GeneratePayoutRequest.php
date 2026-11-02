<?php

namespace App\Http\Requests\Payout;

use App\Models\Payout;
use Illuminate\Foundation\Http\FormRequest;

/** زر "توليد المستحقات" لكل المعلمين — بلا مدخلات: كل غير المصروف حتى لحظة الضغط */
class GeneratePayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('generate', Payout::class);
    }

    public function rules(): array
    {
        return [];
    }
}
