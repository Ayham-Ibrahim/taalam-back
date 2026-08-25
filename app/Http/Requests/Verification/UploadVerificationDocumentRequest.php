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
            // صورة أو PDF فقط، 5 ميغابايت كحد أقصى — يمنع رفع HTML/فيديوهات/سكربتات
            // كوثائق توثيق. الفحص الفعلي الحاسم يبقى في FileStorage::storeFile()
            // (يفحص المحتوى الفعلي لا الامتداد المُدَّعى فقط)؛ هذا الفحص هنا يرفض
            // الطلب مبكراً برسالة واضحة بدل الوصول لطبقة التخزين أصلاً.
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:5120'],
        ];
    }
}
