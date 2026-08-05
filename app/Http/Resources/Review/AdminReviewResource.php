<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_name' => $this->whenLoaded('student', fn () => $this->student?->user?->name),
            'teacher_name' => $this->whenLoaded('teacher', fn () => $this->teacher?->user?->name),
            'rating' => $this->rating,
            'comment' => $this->comment,
            'response' => $this->response,
            'is_hidden' => $this->is_hidden,
            'hidden_reason' => $this->hidden_reason,
            'is_reported' => $this->is_reported,
            'report_reason' => $this->report_reason,
            'created_at' => $this->created_at,
        ];
    }
}
