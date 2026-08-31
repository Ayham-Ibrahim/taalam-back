<?php

namespace App\Http\Resources\Package;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * المعلم يرى سعره وسعر الطالب النهائي، لكن لا يرى نسبة المنصة ولا عائدها (قرار معماري §2).
 */
class TeacherPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacher_id' => $this->teacher_id,
            'title' => $this->title,
            'description' => $this->description,
            'subject_id' => $this->subject_id,
            'session_format' => $this->session_format,
            'capacity' => $this->capacity,
            'enrolled_count' => $this->enrolled_count,
            'sessions_count' => $this->sessions_count,
            'teacher_price' => (float) $this->teacher_price,
            'student_price' => $this->student_price !== null ? (float) $this->student_price : null,
            'currency' => $this->currency,
            'discount_percent' => $this->discount_percent !== null ? (float) $this->discount_percent : null,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'rejection_reason' => $this->rejection_reason,
            'curricula' => $this->whenLoaded('curricula'),
            'stages' => $this->whenLoaded('stages'),
            'grades' => $this->grades,
            'schedules' => $this->whenLoaded('schedules', fn () => $this->schedules->map(fn ($s) => [
                'id' => $s->id, 'date' => $s->date?->toDateString(), 'day_of_week' => $s->day_of_week, 'start_time' => $s->start_time, 'end_time' => $s->end_time,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
