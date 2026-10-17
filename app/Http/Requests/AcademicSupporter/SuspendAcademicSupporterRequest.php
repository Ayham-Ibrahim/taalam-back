<?php

namespace App\Http\Requests\AcademicSupporter;

use App\Models\AcademicSupporter;
use Illuminate\Foundation\Http\FormRequest;

class SuspendAcademicSupporterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('suspend', AcademicSupporter::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
