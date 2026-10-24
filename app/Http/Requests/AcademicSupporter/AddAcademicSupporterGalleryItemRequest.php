<?php

namespace App\Http\Requests\AcademicSupporter;

use Illuminate\Foundation\Http\FormRequest;

class AddAcademicSupporterGalleryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('academicSupporter'));
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,jpeg,jpg,png,pptx,ppt,xlsx,xls,docx,doc',
            ],
            // صورة مصغّرة اختيارية يرفعها الداعم يدوياً — بلا توليد تلقائي (راجع تعليق الهجرة)
            // jpeg/png فقط لتطابق FileStorage::storeFile('img') تماماً
            'thumbnail' => [
                'nullable',
                'image',
                'max:5120',
                'mimes:jpeg,jpg,png',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'يرجى اختيار ملف',
            'file.max' => 'حجم الملف لا يجب أن يتجاوز 20 ميغابايت',
            'thumbnail.image' => 'الصورة المصغّرة يجب أن تكون صورة',
            'thumbnail.max' => 'حجم الصورة المصغّرة لا يجب أن يتجاوز 5 ميغابايت',
        ];
    }
}
