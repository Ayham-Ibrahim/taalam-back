<?php

namespace App\Http\Requests\Package;

use Illuminate\Foundation\Http\FormRequest;

/**
 * باقة حملة: فردية دائماً — schedules نوافذ متاحة يحددها الأدمن بغض النظر عن أيام توفّر
 * المعلم: يوم أسبوع مع ساعات (start_time/end_time) اختيارية — بلا ساعات = اليوم كله، ويجوز
 * أكثر من نافذة لليوم نفسه. الأوقات بمنطقة schedule_timezone (افتراضياً منطقة الأدمن)،
 * والطالب يختار موعد كل جلسة ضمنها (PackageService::syncCampaignSchedules).
 */
class CreateCampaignPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'sessions_count' => ['required', 'integer', 'min:1', 'max:50'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'schedules' => ['required', 'array', 'min:1'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'schedules.*.start_time' => ['nullable', 'required_with:schedules.*.end_time', 'date_format:H:i'],
            'schedules.*.end_time' => ['nullable', 'required_with:schedules.*.start_time', 'date_format:H:i', 'after:schedules.*.start_time'],
            'schedule_timezone' => ['nullable', 'string', 'timezone:all_with_bc'],
            'curriculum_ids' => ['nullable', 'array'],
            'curriculum_ids.*' => ['integer', 'exists:curricula,id'],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer', 'exists:stages,id'],
            'grades' => ['nullable', 'array'],
            'grades.*' => ['integer', 'min:1', 'max:12'],
        ];
    }

    public function attributes(): array
    {
        return [
            'teacher_id' => 'المعلم',
            'title' => 'عنوان الباقة',
            'sessions_count' => 'عدد الجلسات',
            'price' => 'السعر',
            'schedules' => 'الأوقات المتاحة',
            'schedules.*.start_time' => 'بداية الوقت المتاح',
            'schedules.*.end_time' => 'نهاية الوقت المتاح',
            'schedule_timezone' => 'المنطقة الزمنية',
        ];
    }
}
