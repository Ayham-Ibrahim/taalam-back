<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * الطالب الأساسي يضيف أخاً — نفس الحقول الأكاديمية في RegisterStudentRequest
 * بلا إيميل/هاتف/كلمة مرور (حساب الأخ يُفتح بالتبديل من الحساب الأساسي فقط).
 * الإضافة مسموحة لحساب طالب أساسي فقط — حساب أخ لا يضيف إخوة (مستوى واحد).
 */
class AddSiblingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isStudent() && ! $user->isSiblingAccount();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'education_type' => ['required', Rule::in(['school', 'university', 'training'])],

            'curriculum_id' => ['required_if:education_type,school', 'nullable', 'exists:curricula,id'],
            'stage_id' => ['required_if:education_type,school', 'nullable', 'exists:stages,id'],
            'grade' => ['nullable', 'integer', 'min:1', 'max:12'],

            'university_id' => ['required_if:education_type,university', 'nullable', 'exists:universities,id'],
            'major_id' => ['required_if:education_type,university', 'nullable', 'exists:majors,id'],
            'academic_level' => ['required_if:education_type,university', 'nullable', Rule::in(['diploma', 'bachelor', 'master'])],

            'course_field_id' => ['required_if:education_type,training', 'nullable', 'exists:course_fields,id'],
            'level' => ['required_if:education_type,training', 'nullable', Rule::in(['beginner', 'intermediate', 'advanced'])],

            'birth_date' => ['nullable', 'date', 'before:today'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'guardian_phone' => ['nullable', 'string', 'max:25'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'education_type' => 'نوع التعليم',
            'curriculum_id' => 'المنهج',
            'stage_id' => 'المرحلة',
            'grade' => 'الصف الدراسي',
            'university_id' => 'الجامعة',
            'major_id' => 'التخصص',
            'course_field_id' => 'مجال الدورة',
        ];
    }
}
