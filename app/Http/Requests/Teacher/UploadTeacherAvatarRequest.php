<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * يوازي App\Http\Requests\Profile\UploadAvatarRequest تماماً (نفس قواعد الصورة)
 * لكن للأدمن يرفعها نيابة عن معلم بدل رفع المستخدم لصورته هو نفسه —
 * authorize() هنا تتحقق من صلاحية التعديل على المعلم المستهدَف تحديداً.
 */
class UploadTeacherAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('teacher'));
    }

    public function rules(): array
    {
        return [
            // 15 ميغابايت — صور كاميرا الهاتف الحديثة (غالبية صور المعلمين) غالباً
            // 4-12 ميغابايت، كان الحد السابق (5) يرفض كثيراً منها بصمت.
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:15360', 'dimensions:min_width=400,min_height=400'],
        ];
    }

    public function attributes(): array
    {
        return ['avatar' => 'الصورة الشخصية'];
    }

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
