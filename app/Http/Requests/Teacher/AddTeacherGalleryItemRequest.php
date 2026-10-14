<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class AddTeacherGalleryItemRequest extends FormRequest
{
    /** الغاليري مقصورة على الداعم الأكاديمي — TeacherPolicy::update وحدها لا تكفي هنا. */
    public function authorize(): bool
    {
        $teacher = $this->route('teacher');

        return $this->user()->can('update', $teacher) && $teacher->isAcademicSupport();
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,jpeg,jpg,png,pptx,ppt,xlsx,xls',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'يرجى اختيار ملف',
            'file.max' => 'حجم الملف لا يجب أن يتجاوز 20 ميغابايت',
        ];
    }
}
