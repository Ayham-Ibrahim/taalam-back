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
            'curriculum_id' => $this->curriculum_id,
            'stage' => $this->whenLoaded('stage', fn () => $this->stage?->name_ar),
            'stage_id' => $this->stage_id,
            'grade' => $this->grade,
            'university' => $this->whenLoaded('university', fn () => $this->university?->name_ar),
            'university_id' => $this->university_id,
            'major' => $this->whenLoaded('major', fn () => $this->major?->name_ar),
            'major_id' => $this->major_id,
            'academic_level' => $this->academic_level,
            'course_field' => $this->whenLoaded('courseField', fn () => $this->courseField?->name_ar),
            'course_field_id' => $this->course_field_id,
            'level' => $this->level,
            // الحقول التالية لا يعرضها ملف "طلابي" لدى المعلم عادةً، لكنها ضرورية لتعبئة
            // نموذج الإعدادات الذاتي للطالب — لا ضرر من عرضها هنا (الطالب/الأدمن فقط يريان هذا المورد)
            'whatsapp' => $this->user->whatsapp,
            'gender' => $this->user->gender,
            'birth_date' => $this->birth_date,
            'guardian_name' => $this->guardian_name,
            'guardian_phone' => $this->guardian_phone,
            'created_at' => $this->created_at,
        ];
    }
}
