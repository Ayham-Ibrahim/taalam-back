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
        return [
            'title' => ['required', 'string', 'max:200'],
            'company' => ['nullable', 'string', 'max:200'],
            // المركز التدريبي: "دورة متاحة" = الاسم + الوصف (إلزامي) بلا فترة؛ المدرس: خبرة بفترة إلزامية
            'period' => [$this->isTrainingCenter() ? 'nullable' : 'required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => [$this->isTrainingCenter() ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    private function isTrainingCenter(): bool
    {
        return (bool) $this->route('experience')?->teacher?->isTrainingCenter();
    }

    public function attributes(): array
    {
        if ($this->isTrainingCenter()) {
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
