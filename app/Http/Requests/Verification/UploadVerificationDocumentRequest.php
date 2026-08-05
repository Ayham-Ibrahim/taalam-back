<?php

namespace App\Http\Requests\Verification;

use App\Models\VerificationDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadVerificationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [VerificationDocument::class, $this->route('teacher')]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['identity', 'academic', 'experience', 'professional', 'security', 'commercial'])],
            'file' => ['required', 'file', 'max:10240'],
        ];
    }
}
