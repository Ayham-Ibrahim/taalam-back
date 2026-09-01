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

    /**
     * لا يوجد lang/ar في هذا المشروع (APP_LOCALE=en) — بلا messages() صريحة
     * هنا تصل رسائل Laravel الافتراضية بالإنجليزية بصمت، بعكس كل طلبات
     * الرفع/الإنشاء الأخرى في هذا التطبيق التي تُعرِّف رسائل عربية صراحة.
     */
    public function messages(): array
    {
        return [
            'type.required' => 'يرجى اختيار نوع الوثيقة',
            'type.in' => 'نوع الوثيقة غير صالح',
            'file.required' => 'يرجى اختيار ملف',
            'file.file' => 'الملف المُرسَل غير صالح',
            'file.mimes' => 'صيغة الملف غير مدعومة — يُسمح فقط بصورة (JPG/PNG) أو PDF',
            'file.max' => 'حجم الملف أكبر من الحد المسموح (5 ميغابايت كحد أقصى)',
        ];
    }
}
