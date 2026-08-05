<?php

namespace App\Http\Resources\Session;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * رابط الانضمام يُعرض حسب دور طالب النداء فقط — لا يُسرَّب رابط/كلمة سر
 * الطرف الآخر (الطالب لا يرى join_url_teacher والعكس). الأدمن يرى الاثنين.
 */
class ClassSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user?->isAdmin() ?? false;
        $isTeacher = $isAdmin || $user?->loadMissing('teacher')->teacher?->id === $this->teacher_id;
        $isStudent = $isAdmin || ($user?->loadMissing('student')->student
            && $this->attendees->contains('student_id', $user->student->id));

        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'course_id' => $this->course_id,
            'teacher_id' => $this->teacher_id,
            'sequence_no' => $this->sequence_no,
            'scheduled_at' => $this->scheduled_at,
            'duration_min' => $this->duration_min,
            'original_scheduled_at' => $this->original_scheduled_at,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'cancellation_reason' => $this->cancellation_reason,
            'is_makeup' => $this->is_makeup,
            'makeup_for_session_id' => $this->makeup_for_session_id,
            'recording_url' => $this->recording_url,
            'join_url_teacher' => $isTeacher ? $this->join_url_teacher : null,
            'join_url_student' => $isStudent ? $this->join_url_student : null,
            'teacher' => $this->whenLoaded('teacher'),
            'booking' => $this->whenLoaded('booking'),
            'course' => $this->whenLoaded('course'),
            'attendees' => $this->whenLoaded('attendees'),
        ];
    }
}
