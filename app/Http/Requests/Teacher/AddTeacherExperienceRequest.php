<?php

namespace App\Http\Requests\Teacher;

use App\Models\TeacherExperience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddTeacherExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('teacher'));
    }

    public function rules(): array
    {
        $isCourse = $this->isCourseEntry();

        return [
            // offered_course ("الدورات المتاحة" كقسم مستقل) للمدرس الجامعي حصراً؛ المركز التدريبي
            // قسمه كله دورات أصلاً فيبقى بلا kind
            'kind' => [
                'nullable',
                Rule::in([TeacherExperience::KIND_EXPERIENCE, TeacherExperience::KIND_OFFERED_COURSE]),
                function ($attribute, $value, $fail) {
                    if ($value === TeacherExperience::KIND_OFFERED_COURSE && $this->route('teacher')?->teacher_type !== 'university') {
                        $fail('قسم الدورات المتاحة مخصص للمدرسين الجامعيين.');
                    }
                },
            ],
            'title' => ['required', 'string', 'max:200'],
            'company' => ['nullable', 'string', 'max:200'],
            // "دورة متاحة" = الاسم + الوصف (إلزامي) بلا فترة؛ الخبرة: فترة إلزامية
            'period' => [$isCourse ? 'nullable' : 'required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => [$isCourse ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    /** دورة متاحة: كل مدخلات المركز التدريبي، أو مدخل offered_course للمدرس الجامعي */
    private function isCourseEntry(): bool
    {
        return (bool) $this->route('teacher')?->isTrainingCenter()
            || $this->input('kind') === TeacherExperience::KIND_OFFERED_COURSE;
    }

    public function attributes(): array
    {
        if ($this->isCourseEntry()) {
            return ['title' => 'اسم الدورة', 'description' => 'وصف الدورة'];
        }

        return [
            'title' => 'المسمى',
            'company' => 'الشركة أو الجهة أو المؤسسة',
            'period' => 'الفترة',
            'location' => 'الموقع',
            'description' => 'الوصف',
        ];
    }
}
