<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** نفس حقول RegisterStudentRequest الأكاديمية — لكن هنا لإكمال ملف حساب أنشأه الأدمن مسبقاً، لا لتسجيل جديد */
class UpdateStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('student'));
    }

    public function rules(): array
    {
        return [
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
            'education_type' => 'نوع التعليم',
            'curriculum_id' => 'المنهج',
            'stage_id' => 'المرحلة',
            'university_id' => 'الجامعة',
            'major_id' => 'التخصص',
            'course_field_id' => 'مجال الدورة',
        ];
    }
}
