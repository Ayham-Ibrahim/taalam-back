<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * علاقات teacher/student/academicSupporter لا تُحمَّل دائماً — فقط عند تحميلها صراحة حسب دور
 * المستخدم (AuthService::login) — whenLoaded تمنع ظهور مفتاح فارغ حين لا داعي له.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            // حساب أخ مضاف على حساب طالب أساسي (بلا إيميل — يُفتح بالتبديل فقط)
            'primary_user_id' => $this->primary_user_id,
            'is_sibling_account' => $this->primary_user_id !== null,
            'avatar_path' => $this->avatar_path,
            'timezone' => $this->timezone,
            'timezone_auto' => $this->timezone_auto,
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher ? new TeacherResource($this->teacher) : null),
            'student' => $this->whenLoaded('student', fn () => $this->student ? new StudentResource($this->student) : null),
            'academicSupporter' => $this->whenLoaded('academicSupporter', fn () => $this->academicSupporter ? [
                'id' => $this->academicSupporter->id,
                'status' => $this->academicSupporter->status,
            ] : null),
        ];
    }
}
