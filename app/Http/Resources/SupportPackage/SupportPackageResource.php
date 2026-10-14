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
            'teacher_id' => $this->teacher_id,
            'teacher' => $this->whenLoaded('teacher', fn () => [
                'id' => $this->teacher->id,
                'teacher_type' => $this->teacher->teacher_type,
                'name' => $this->teacher->user?->name,
            ]),
            'tier' => $this->tier,
            'description' => $this->description,
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => $this->subject?->name_ar),
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
