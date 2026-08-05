<?php

namespace App\Http\Resources\Package;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * الطالب لا يرى سعر المعلم إطلاقاً — فقط السعر النهائي (§2 في القرارات المعمارية).
 */
class StudentPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => $this->subject?->name_ar),
            'session_format' => $this->session_format,
            'capacity' => $this->capacity,
            'enrolled_count' => $this->enrolled_count,
            'sessions_count' => $this->sessions_count,
            'student_price' => $this->student_price !== null ? (float) $this->student_price : null,
            'currency' => $this->currency,
            'discount_percent' => $this->discount_percent !== null ? (float) $this->discount_percent : null,
            'status' => $this->status,
            'curricula' => $this->whenLoaded('curricula'),
            'stages' => $this->whenLoaded('stages'),
            'schedules' => $this->whenLoaded('schedules', fn () => $this->schedules->map(fn ($s) => [
                'id' => $s->id, 'date' => $s->date?->toDateString(), 'day_of_week' => $s->day_of_week, 'start_time' => $s->start_time, 'end_time' => $s->end_time,
            ])),
        ];
    }
}
