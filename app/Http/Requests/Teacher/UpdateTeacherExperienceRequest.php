<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeacherExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('experience')->teacher);
    }

    public function rules(): array
    {
        $isCourse = $this->isCourseEntry();

        return [
            'title' => ['required', 'string', 'max:200'],
            'company' => ['nullable', 'string', 'max:200'],
            // "دورة متاحة" = الاسم + الوصف (إلزامي) بلا فترة؛ الخبرة: فترة إلزامية
            'period' => [$isCourse ? 'nullable' : 'required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => [$isCourse ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    /** نوع المدخل ثابت لا يُغيَّر بالتعديل — يُقرأ من السجل نفسه */
    private function isCourseEntry(): bool
    {
        return (bool) $this->route('experience')?->isOfferedCourse();
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
