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
            // أحرف فقط (بلا أرقام أو رموز) — نفس نمط CreateTeacherAccountRequest::name
            'guardian_name' => ['required', 'string', 'max:150', 'regex:/^[\p{L}\p{M}]+(?:[\s\'-][\p{L}\p{M}]+)*$/u'],
            // أرقام فقط (٧–٢٤ خانة) مع بادئة + اختيارية — نفس نمط بقية حقول الهاتف
            'guardian_phone' => ['required', 'string', 'max:25', 'regex:/^\+?[0-9]{7,24}$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'education_type' => 'نوع التعليم',
            'curriculum_id' => 'المنهج',
            'stage_id' => 'المرحلة',
            'grade' => 'الصف الدراسي',
            'university_id' => 'الجامعة',
            'major_id' => 'التخصص',
            'course_field_id' => 'مجال الدورة',
            'guardian_name' => 'اسم ولي الأمر',
            'guardian_phone' => 'هاتف ولي الأمر',
        ];
    }

    public function messages(): array
    {
        return [
            'grade.integer' => 'الصف الدراسي يجب أن يكون رقمًا صحيحًا بين 1 و 12.',
            'grade.min' => 'الصف الدراسي يجب أن يكون بين 1 و 12.',
            'grade.max' => 'الصف الدراسي يجب أن يكون بين 1 و 12.',
            'guardian_name.required' => 'اسم ولي الأمر مطلوب.',
            'guardian_name.regex' => 'اسم ولي الأمر يجب أن يحتوي على أحرف فقط من دون أرقام أو رموز.',
            'guardian_phone.required' => 'رقم هاتف ولي الأمر مطلوب.',
            'guardian_phone.regex' => 'رقم هاتف ولي الأمر يجب أن يحتوي أرقامًا فقط (من 7 إلى 24 رقمًا)، ويمكن أن يبدأ بعلامة + للرقم الدولي.',
        ];
    }
}
