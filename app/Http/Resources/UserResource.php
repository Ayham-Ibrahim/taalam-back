<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * علاقتا teacher/student لا تُحمَّلان دائماً — فقط عند تحميلهما صراحة حسب دور
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
            'avatar_path' => $this->avatar_path,
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher ? new TeacherResource($this->teacher) : null),
            'student' => $this->whenLoaded('student', fn () => $this->student ? new StudentResource($this->student) : null),
        ];
    }
}
