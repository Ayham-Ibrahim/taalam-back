<?php

namespace App\Http\Requests\Review;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;

class HideReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hide', Review::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
