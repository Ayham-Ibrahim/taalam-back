<?php

namespace App\Http\Requests\Package;

use App\Models\Package;
use App\Rules\CapacityMatchesFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Package::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'session_format' => ['required', Rule::in(['individual', 'group'])],
            'capacity' => ['required', 'integer', 'min:1', new CapacityMatchesFormat],
            'sessions_count' => ['required', 'integer', 'min:1'],
            'teacher_price' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'curriculum_ids' => ['nullable', 'array'],
            'curriculum_ids.*' => ['integer', 'exists:curricula,id'],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', 'exists:stages,id'],
            /**
             * جماعية: المعلم يحدد تاريخ أول جلسة ووقتها صراحةً — لا اختيار طالب لاحقاً،
             *   والتكرار أسبوعي من هذا التاريخ. day_of_week يُشتق من date، وend_time من
             *   session_duration_minutes — كلاهما ليس مدخلاً.
             * فردية: المعلم يحدد فقط الأيام المتاحة لها من ضمن أيام توفّره العامة
             *   (availability_slots) — بلا تاريخ أو وقت؛ التحقق من أن اليوم ضمن أيامه
             *   يتم في PackageService لأنه يحتاج استعلام قاعدة بيانات.
             */
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.date' => ['nullable', 'required_if:session_format,group', 'date', 'after_or_equal:today'],
            'schedules.*.start_time' => ['nullable', 'required_if:session_format,group', 'date_format:H:i'],
            'schedules.*.day_of_week' => ['nullable', 'required_if:session_format,individual', 'integer', 'min:0', 'max:6'],
        ];
    }
}
