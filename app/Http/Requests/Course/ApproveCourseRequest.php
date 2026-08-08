<?php

namespace App\Http\Requests\Course;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;

class ApproveCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', Course::class);
    }

    public function rules(): array
    {
        return [
            // الحد الفعلي القابل للتعديل عبر الإعدادات — هذا سقف دفاعي ثابت فقط (CourseApprovalService هو المرجع الحقيقي)
            'platform_margin_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
        ];
    }
}
