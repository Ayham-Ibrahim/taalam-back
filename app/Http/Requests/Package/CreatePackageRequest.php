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

    /**
     * required وحدها لا تكفي لرفض قيمة "مسافة فقط" — Laravel يعتبرها "موجودة"
     * (ليست null ولا سلسلة فارغة حرفياً). التقليم هنا قبل الفحص يحوّل المسافات
     * وحدها إلى سلسلة فارغة فعلية فترفضها required بشكل صحيح.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => is_string($this->description) ? trim($this->description) : $this->description,
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:2000'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'session_format' => ['required', Rule::in(['individual', 'group'])],
            'capacity' => ['required', 'integer', 'min:1', 'max:100', new CapacityMatchesFormat],
            'sessions_count' => ['required', 'integer', 'min:1', 'max:200'],
            'teacher_price' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'curriculum_ids' => ['nullable', 'array', 'max:20'],
            'curriculum_ids.*' => ['integer', 'exists:curricula,id'],
            'stage_ids' => ['nullable', 'array', 'max:20'],
            'stage_ids.*' => ['integer', 'exists:stages,id'],
            'grades' => ['nullable', 'array', 'max:12'],
            'grades.*' => ['integer', 'distinct', 'min:1', 'max:12'],
            /**
             * جماعية: المعلم يحدد تاريخ أول جلسة ووقتها صراحةً — لا اختيار طالب لاحقاً،
             *   والتكرار أسبوعي من هذا التاريخ. day_of_week يُشتق من date، وend_time من
             *   session_duration_minutes — كلاهما ليس مدخلاً.
             * فردية: المعلم يحدد فقط الأيام المتاحة لها من ضمن أيام توفّره العامة
             *   (availability_slots) — بلا تاريخ أو وقت؛ التحقق من أن اليوم ضمن أيامه
             *   يتم في PackageService لأنه يحتاج استعلام قاعدة بيانات.
             */
            'schedules' => ['required', 'array', 'min:1', 'max:200'],
            'schedules.*.date' => ['nullable', 'required_if:session_format,group', 'date', 'after_or_equal:today'],
            'schedules.*.start_time' => ['nullable', 'required_if:session_format,group', 'date_format:H:i'],
            'schedules.*.day_of_week' => ['nullable', 'required_if:session_format,individual', 'integer', 'min:0', 'max:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان الباقة مطلوب',
            'title.max' => 'عنوان الباقة يجب ألا يتجاوز 180 حرفاً',
            'description.required' => 'الوصف حقل إلزامي ولا يمكن أن يكون فارغاً',
            'description.max' => 'الوصف يجب ألا يتجاوز 2000 حرف',
            'subject_id.required' => 'يرجى اختيار المادة',
            'subject_id.exists' => 'المادة المختارة غير موجودة',
            'session_format.required' => 'يرجى اختيار نوع الباقة',
            'session_format.in' => 'نوع الباقة غير صالح',
            'capacity.required' => 'يرجى تحديد السعة',
            'capacity.integer' => 'السعة يجب أن تكون رقماً صحيحاً',
            'capacity.min' => 'السعة يجب ألا تقل عن 1',
            'capacity.max' => 'السعة يجب ألا تتجاوز 100',
            'sessions_count.required' => 'يرجى تحديد عدد الجلسات',
            'sessions_count.integer' => 'عدد الجلسات يجب أن يكون رقماً صحيحاً',
            'sessions_count.min' => 'عدد الجلسات يجب ألا يقل عن 1',
            'sessions_count.max' => 'عدد الجلسات يجب ألا يتجاوز 200',
            'teacher_price.required' => 'يرجى تحديد سعر الساعة',
            'teacher_price.numeric' => 'سعر الساعة يجب أن يكون رقماً',
            'teacher_price.min' => 'سعر الساعة يجب ألا يقل عن 0.01',
            'teacher_price.max' => 'سعر الساعة يجب ألا يتجاوز 100000',
            'currency.size' => 'رمز العملة يجب أن يكون 3 أحرف',
            'discount_percent.numeric' => 'نسبة الخصم يجب أن تكون رقماً',
            'discount_percent.min' => 'نسبة الخصم يجب ألا تقل عن 0',
            'discount_percent.max' => 'نسبة الخصم يجب ألا تتجاوز 100',
            'curriculum_ids.max' => 'لا يمكن اختيار أكثر من 20 منهجاً',
            'curriculum_ids.*.exists' => 'أحد المناهج المختارة غير موجود',
            'stage_ids.max' => 'لا يمكن اختيار أكثر من 20 مرحلة',
            'stage_ids.*.exists' => 'إحدى المراحل المختارة غير موجودة',
            'grades.max' => 'لا يمكن اختيار أكثر من 12 صفاً',
            'grades.*.distinct' => 'لا يمكن اختيار نفس الصف أكثر من مرة',
            'grades.*.min' => 'الصف يجب أن يكون بين 1 و12',
            'grades.*.max' => 'الصف يجب أن يكون بين 1 و12',
            'schedules.required' => 'يرجى إضافة موعد واحد على الأقل',
            'schedules.min' => 'يرجى إضافة موعد واحد على الأقل',
            'schedules.max' => 'لا يمكن إضافة أكثر من 200 موعد',
            'schedules.*.date.required_if' => 'يرجى تحديد تاريخ كل جلسة',
            'schedules.*.date.date' => 'تاريخ الجلسة غير صالح',
            'schedules.*.date.after_or_equal' => 'لا يمكن اختيار تاريخ جلسة في الماضي',
            'schedules.*.start_time.required_if' => 'يرجى تحديد وقت بدء كل جلسة',
            'schedules.*.start_time.date_format' => 'صيغة وقت الجلسة غير صحيحة',
            'schedules.*.day_of_week.required_if' => 'يرجى اختيار يوم واحد على الأقل من أيام التوفّر',
            'schedules.*.day_of_week.integer' => 'اليوم المختار غير صالح',
            'schedules.*.day_of_week.min' => 'اليوم المختار غير صالح',
            'schedules.*.day_of_week.max' => 'اليوم المختار غير صالح',
        ];
    }
}
