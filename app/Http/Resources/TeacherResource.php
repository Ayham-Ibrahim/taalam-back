<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** نسخة مختصرة — تُستخدم فقط ضمن UserResource عند تحميل علاقة teacher (مثلاً عند تسجيل الدخول) */
class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacher_type' => $this->teacher_type,
            'status' => $this->status,
        ];
    }
}
