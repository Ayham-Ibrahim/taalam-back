<?php

namespace App\Http\Requests\Teacher;

use App\Rules\ValidYoutubeVideo;
use Illuminate\Foundation\Http\FormRequest;

class AddTeacherVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('teacher'));
    }

    /** يطبّع أي صيغة رابط يوتيوب مقبولة إلى معرّف الفيديو النظيف قبل التحقق — نفس منطق UpdateTeacherProfileRequest::intro_youtube_id */
    protected function prepareForValidation(): void
    {
        if ($this->filled('youtube_id')) {
            $extracted = ValidYoutubeVideo::extractId($this->input('youtube_id'));
            if ($extracted !== null) {
                $this->merge(['youtube_id' => $extracted]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'youtube_id' => ['required', new ValidYoutubeVideo],
            'title' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'youtube_id.required' => 'يرجى إدخال رابط فيديو يوتيوب',
        ];
    }
}
