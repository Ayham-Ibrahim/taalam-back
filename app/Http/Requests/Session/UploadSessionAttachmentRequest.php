<?php

namespace App\Http\Requests\Session;

use App\Models\SessionAttachment;
use Illuminate\Foundation\Http\FormRequest;

class UploadSessionAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [SessionAttachment::class, $this->route('session')]);
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:150'],
            // PDF وصور وWord فقط، 10 ميغابايت كحد أقصى — الفحص الحاسم يبقى في
            // FileStorage::storeFile('attachment')؛ هذا يرفض الطلب مبكراً برسالة واضحة.
            'file' => ['required', 'file', 'mimes:pdf,jpeg,jpg,png,doc,docx', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'العنوان طويل جداً (150 حرفاً كحد أقصى)',
            'file.required' => 'يرجى اختيار ملف',
            'file.file' => 'الملف المُرسَل غير صالح',
            'file.mimes' => 'صيغة الملف غير مدعومة — يُسمح فقط بـ PDF أو صورة (JPG/PNG) أو Word',
            'file.max' => 'حجم الملف أكبر من الحد المسموح (10 ميغابايت كحد أقصى)',
        ];
    }
}
