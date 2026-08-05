<?php

namespace App\Http\Resources\Course;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCourseResource extends JsonResource
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
            'course_field_id' => $this->course_field_id,
            'course_field' => $this->whenLoaded('courseField', fn () => $this->courseField?->name_ar),
            'subject_id' => $this->subject_id,
            'level' => $this->level,
            'delivery_mode' => $this->delivery_mode,
            'location' => $this->location,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'total_sessions' => $this->total_sessions,
            'session_duration_min' => $this->session_duration_min,
            'total_hours' => $this->total_hours,
            'max_seats' => $this->max_seats,
            'enrolled_count' => $this->enrolled_count,
            'provider_price' => (float) $this->provider_price,
            'pricing_mode' => $this->pricing_mode,
            'platform_margin_percent' => $this->platform_margin_percent !== null ? (float) $this->platform_margin_percent : null,
            'student_price' => $this->student_price !== null ? (float) $this->student_price : null,
            'platform_revenue' => $this->platform_revenue !== null ? (float) $this->platform_revenue : null,
            'currency' => $this->currency,
            'has_certificate' => $this->has_certificate,
            'certificate_type' => $this->certificate_type,
            'certificate_issuer' => $this->certificate_issuer,
            'certificate_requirements' => $this->certificate_requirements,
            'requires_laptop' => $this->requires_laptop,
            'materials_included' => $this->materials_included,
            'has_practical_exercises' => $this->has_practical_exercises,
            'sessions_recorded' => $this->sessions_recorded,
            'prerequisites' => $this->prerequisites,
            'cancellation_policy' => $this->cancellation_policy,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'rejection_reason' => $this->rejection_reason,
            'curricula' => $this->whenLoaded('curricula'),
            'schedules' => $this->whenLoaded('schedules'),
            'created_at' => $this->created_at,
        ];
    }
}
