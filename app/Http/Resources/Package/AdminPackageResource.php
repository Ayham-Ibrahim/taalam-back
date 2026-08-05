<?php

namespace App\Http\Resources\Package;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * الأدمن يرى كل شيء: سعر المعلم، النسبة، السعر النهائي، عائد المنصة.
 */
class AdminPackageResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => $this->subject?->name_ar),
            'session_format' => $this->session_format,
            'capacity' => $this->capacity,
            'enrolled_count' => $this->enrolled_count,
            'sessions_count' => $this->sessions_count,
            'teacher_price' => (float) $this->teacher_price,
            'platform_margin_percent' => $this->platform_margin_percent !== null ? (float) $this->platform_margin_percent : null,
            'student_price' => $this->student_price !== null ? (float) $this->student_price : null,
            'platform_revenue' => $this->platform_revenue !== null ? (float) $this->platform_revenue : null,
            'currency' => $this->currency,
            'discount_percent' => $this->discount_percent !== null ? (float) $this->discount_percent : null,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'rejection_reason' => $this->rejection_reason,
            'curricula' => $this->whenLoaded('curricula'),
            'stages' => $this->whenLoaded('stages'),
            'schedules' => $this->whenLoaded('schedules', fn () => $this->schedules->map(fn ($s) => [
                'id' => $s->id, 'date' => $s->date?->toDateString(), 'day_of_week' => $s->day_of_week, 'start_time' => $s->start_time, 'end_time' => $s->end_time,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
