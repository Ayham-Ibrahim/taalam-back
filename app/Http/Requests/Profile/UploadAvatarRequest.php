<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UploadAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // بدقة جيدة: 400×400 كحد أدنى، بلا حد أقصى للأبعاد. 15 ميغابايت — صور
            // كاميرا الهاتف الحديثة غالباً 4-12 ميغابايت، كان الحد السابق (5) يرفض كثيراً منها بصمت.
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:15360', 'dimensions:min_width=400,min_height=400'],
        ];
    }

    public function attributes(): array
    {
        return ['avatar' => 'الصورة الشخصية'];
    }

    /**
     * لا يوجد lang/ar في هذا المشروع (APP_LOCALE=en) — بلا messages() صريحة
     * هنا تصل الرسائل بالإنجليزية الكاملة، لا فقط اسم الحقل (نفس فجوة
     * UploadVerificationDocumentRequest/TaxonomyItemRequest المُصلَحة سابقاً).
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'يرجى اختيار صورة',
            'avatar.image' => 'الملف المُرسَل يجب أن يكون صورة',
            'avatar.mimes' => 'صيغة الصورة غير مدعومة — يُسمح فقط بصورة JPG أو PNG',
            'avatar.max' => 'حجم الصورة أكبر من الحد المسموح (15 ميغابايت كحد أقصى)',
            'avatar.dimensions' => 'أبعاد الصورة صغيرة جداً — الحد الأدنى 400×400 بكسل',
        ];
    }
}
