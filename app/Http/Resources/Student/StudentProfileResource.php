<?php

namespace App\Http\Resources\Student;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** ملف طالب كامل — لصفحة "طلابي" لدى المعلم، أو ملف الطالب نفسه/الأدمن */
class StudentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'avatar_path' => $this->user->avatar_path,
            'education_type' => $this->education_type,
            'curriculum' => $this->whenLoaded('curriculum', fn () => $this->curriculum?->name_ar),
            'stage' => $this->whenLoaded('stage', fn () => $this->stage?->name_ar),
            'grade' => $this->grade,
            'university' => $this->whenLoaded('university', fn () => $this->university?->name_ar),
            'major' => $this->whenLoaded('major', fn () => $this->major?->name_ar),
            'academic_level' => $this->academic_level,
            'course_field' => $this->whenLoaded('courseField', fn () => $this->courseField?->name_ar),
            'level' => $this->level,
            'created_at' => $this->created_at,
        ];
    }
}
