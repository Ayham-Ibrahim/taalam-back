<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** نسخة مختصرة — تُستخدم فقط ضمن UserResource عند تحميل علاقة student (مثلاً عند تسجيل الدخول) */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'education_type' => $this->education_type,
            'stage_id' => $this->stage_id,
            'university_id' => $this->university_id,
        ];
    }
}
