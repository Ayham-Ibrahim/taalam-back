<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UploadAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // بدقة جيدة: 400×400 كحد أدنى، بلا حد أقصى للأبعاد — فقط حجم الملف (5 ميغابايت)
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:5120', 'dimensions:min_width=400,min_height=400'],
        ];
    }

    public function attributes(): array
    {
        return ['avatar' => 'الصورة الشخصية'];
    }
}
