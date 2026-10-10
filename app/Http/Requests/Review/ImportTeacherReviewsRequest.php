<?php

namespace App\Http\Requests\Review;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;

class ImportTeacherReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('import', Review::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:5120'],
        ];
    }
}
