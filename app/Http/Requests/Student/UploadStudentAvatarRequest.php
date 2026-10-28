<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * يوازي App\Http\Requests\Teacher\UploadTeacherAvatarRequest تماماً — الأدمن
 * يرفع صورة نيابة عن طالب بدل رفع المستخدم لصورته هو نفسه.
 */
class UploadStudentAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('student'));
    }

    public function rules(): array
    {
        return [
            // 50 ميغابايت — يتوافق مع حد PHP الفعلي في public/.user.ini.
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:51200', 'dimensions:min_width=400,min_height=400'],
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
            'avatar.max' => 'حجم الصورة أكبر من الحد المسموح (50 ميغابايت كحد أقصى)',
            'avatar.dimensions' => 'أبعاد الصورة صغيرة جداً — الحد الأدنى 400×400 بكسل',
        ];
    }
}
