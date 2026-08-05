<?php

namespace App\Http\Resources\Teacher;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ملف معلم عام لصفحة السوق — بلا وثائق توثيق أو حقول إدارية (rejection_reason،
 * verified_by، commercial_register...). يُستخدم لأي مستخدم غير الأدمن/صاحب الحساب.
 */
class PublicTeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacher_type' => $this->teacher_type,
            'status' => $this->status,
            'display_name_en' => $this->display_name_en,
            'bio' => $this->bio,
            'city' => $this->city,
            'qualification' => $this->qualification,
            'experience_years' => $this->experience_years,
            'age_groups' => $this->age_groups,
            'teaching_methods' => $this->teaching_methods,
            'logo_path' => $this->logo_path,
            'intro_video_path' => $this->intro_video_path,
            'intro_video_seconds' => $this->intro_video_seconds,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_path' => $this->user->avatar_path,
            ],
            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn ($s) => [
                'id' => $s->id, 'name_ar' => $s->name_ar,
            ])),
            'curricula' => $this->whenLoaded('curricula', fn () => $this->curricula->map(fn ($c) => [
                'id' => $c->id, 'name_ar' => $c->name_ar,
            ])),
            'languages' => $this->whenLoaded('languages', fn () => $this->languages->map(fn ($l) => [
                'code' => $l->code, 'name_ar' => $l->name_ar,
            ])),
            'stats' => $this->stats,
        ];
    }
}
