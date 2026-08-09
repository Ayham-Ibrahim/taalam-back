<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** تقييم الطالب لنفسه — يحمل ما يحتاجه لعرضه وتعديله ضمن مهلة التعديل، بخلاف AdminReviewResource الموجَّه للإشراف */
class MyReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_session_id' => $this->class_session_id,
            'teacher_id' => $this->teacher_id,
            'teacher_name' => $this->whenLoaded('teacher', fn () => $this->teacher?->user?->name),
            'teacher_avatar' => $this->whenLoaded('teacher', fn () => $this->teacher?->user?->avatar_path),
            'rating' => $this->rating,
            'comment' => $this->comment,
            'response' => $this->response,
            'responded_at' => $this->responded_at,
            'is_hidden' => $this->is_hidden,
            'can_edit' => $this->edit_deadline !== null && now()->lessThan($this->edit_deadline),
            'edit_deadline' => $this->edit_deadline,
            'created_at' => $this->created_at,
        ];
    }
}
