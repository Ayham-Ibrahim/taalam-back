<?php

namespace App\Http\Requests\Package\Concerns;

use Illuminate\Validation\Rule;

/**
 * باقة التجربة المجانية (is_trial): جلسة تعريفية واحدة فردية بسعر صفر. قيمها الثابتة
 * (النوع/النصاب/عدد الجلسات/السعر) يفرضها PackageService، فإرسالها اختياري لكن بقيمتها
 * الصحيحة فقط. المادة والمناهج والمراحل والصفوف والخصم ممنوعة — لا بيانات مضللة عن
 * محتوى جلسة هدفها التعريف بالمعلم لا تدريس مادة.
 */
trait ValidatesTrialPackage
{
    abstract protected function isTrialPackage(): bool;

    /** الجدول (day_of_week) مشروط بـ session_format=individual — فيُثبَّت قبل الفحص إن لم يُرسل */
    protected function prepareTrialPackage(): void
    {
        if ($this->isTrialPackage() && ! $this->filled('session_format')) {
            $this->merge(['session_format' => 'individual']);
        }
    }

    protected function withTrialPackageRules(array $rules): array
    {
        $rules['is_trial'] = ['sometimes', 'boolean'];

        if (! $this->isTrialPackage()) {
            return $rules;
        }

        unset($rules['curriculum_ids.*'], $rules['stage_ids.*'], $rules['grades.*']);

        return array_merge($rules, [
            'subject_id' => ['prohibited'],
            'session_format' => ['required', Rule::in(['individual'])],
            'capacity' => ['sometimes', 'integer', Rule::in([1])],
            'sessions_count' => ['sometimes', 'integer', Rule::in([1])],
            'teacher_price' => ['sometimes', 'numeric', 'min:0', 'max:0'],
            'discount_percent' => ['prohibited'],
            'curriculum_ids' => ['prohibited'],
            'stage_ids' => ['prohibited'],
            'grades' => ['prohibited'],
        ]);
    }

    /** رسائل الباقة المجانية تحل محل العامة لنفس المفاتيح (teacher_price.max مثلاً) — للمجانية وحدها */
    protected function withTrialPackageMessages(array $messages): array
    {
        $messages['is_trial.boolean'] = 'قيمة الباقة المجانية غير صالحة';

        if (! $this->isTrialPackage()) {
            return $messages;
        }

        return array_merge($messages, [
            'subject_id.prohibited' => 'الباقة المجانية لا ترتبط بمادة',
            'session_format.in' => 'الباقة المجانية فردية فقط',
            'capacity.in' => 'نصاب الباقة المجانية طالب واحد فقط',
            'sessions_count.in' => 'الباقة المجانية جلسة واحدة فقط',
            'teacher_price.min' => 'الباقة المجانية سعرها صفر',
            'teacher_price.max' => 'الباقة المجانية سعرها صفر',
            'discount_percent.prohibited' => 'لا خصم على باقة مجانية',
            'curriculum_ids.prohibited' => 'الباقة المجانية لا ترتبط بمناهج',
            'stage_ids.prohibited' => 'الباقة المجانية لا ترتبط بمراحل',
            'grades.prohibited' => 'الباقة المجانية لا ترتبط بصفوف',
        ]);
    }
}
