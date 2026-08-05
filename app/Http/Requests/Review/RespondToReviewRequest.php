<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class RespondToReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('respond', $this->route('review'));
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'max:1000'],
        ];
    }
}
