<?php

namespace App\Http\Requests\Teacher;

use App\Rules\ValidYoutubeVideo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('teacher'));
    }

    /** يطبّع أي صيغة رابط يوتيوب مقبولة إلى معرّف الفيديو النظيف قبل التحقق — فيُخزَّن دائماً نظيفاً لا كرابط خام */
    protected function prepareForValidation(): void
    {
        if ($this->filled('intro_youtube_id')) {
            $extracted = ValidYoutubeVideo::extractId($this->input('intro_youtube_id'));
            if ($extracted !== null) {
                $this->merge(['intro_youtube_id' => $extracted]);
            }
        }
    }

    public function rules(): array
    {
        $isTrainingCenter = $this->route('teacher')?->isTrainingCenter();

        return [
            'name' => ['nullable', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:500'],
            'teaching_philosophy_quote' => ['nullable', 'string', 'max:200'],
            'teaching_philosophy_text' => ['nullable', 'string', 'max:600'],
            'thank_you_message' => ['nullable', 'string', 'max:600'],
            'intro_youtube_id' => ['nullable', new ValidYoutubeVideo],
            'qualification' => ['nullable', Rule::in(['bachelor', 'master', 'phd', 'professional_cert', 'diploma'])],
            'experience_years' => ['nullable', Rule::in(['under_1', '1_3', '3_5', 'over_5'])],
            'age_groups' => ['nullable', 'array', 'max:20'],
            'age_groups.*' => ['string', 'max:50'],
            'teaching_methods' => ['nullable', 'array', 'max:20'],
            'teaching_methods.*' => ['string', 'max:50'],
            'exam_prep' => ['nullable', 'array', 'max:20'],
            'exam_prep.*' => ['string', 'max:50'],
            'max_daily_sessions' => ['nullable', 'integer', 'min:1', 'max:24'],

            // أحرف فقط (بلا أرقام) — نفس قاعدة حقول الاسم الأخرى، على مستوى
            // الباك اند أيضاً لا الفرونت إند فقط.
            'display_name_en' => [
                $isTrainingCenter ? 'required' : 'nullable', 'string', 'max:180',
                'regex:/^[\p{L}\p{M}]+(?:[\s\'-][\p{L}\p{M}]+)*$/u',
            ],
            'commercial_register' => [$isTrainingCenter ? 'required' : 'nullable', 'string', 'max:60'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],

            'subject_ids' => ['nullable', 'array', 'max:30'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
            'curriculum_ids' => ['nullable', 'array', 'max:20'],
            'curriculum_ids.*' => ['integer', 'exists:curricula,id'],
            'language_ids' => ['nullable', 'array', 'max:20'],
            'language_ids.*' => ['integer', 'exists:languages,id'],
        ];
    }
}
