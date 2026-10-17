<?php

namespace App\Http\Resources\SupportPackage;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * لا فرق بين ما يراه الأدمن وما يراه الداعم الأكاديمي مالكها — بعكس الباقات
 * العادية، لا هامش منصة يُخفى (ثابت 0 دائماً ومعروف للطرفين).
 */
class SupportPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_supporter_id' => $this->academic_supporter_id,
            'academic_supporter' => $this->whenLoaded('academicSupporter', fn () => [
                'id' => $this->academicSupporter->id,
                'name' => $this->academicSupporter->user?->name,
            ]),
            'title' => $this->title,
            'tier' => $this->tier,
            'description' => $this->description,
            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar])),
            'teacher_price' => (float) $this->teacher_price,
            'platform_margin_percent' => (float) $this->platform_margin_percent,
            'student_price' => (float) $this->student_price,
            'platform_revenue' => (float) $this->platform_revenue,
            'currency' => $this->currency,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at,
        ];
    }
}
