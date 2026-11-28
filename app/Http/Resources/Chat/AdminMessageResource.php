<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** اطلاع الأدمن من الشكوى: كل شيء، بما فيه نص المحذوفة وسجل التعديلات */
class AdminMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'type' => $this->type,
            'body' => $this->body,
            'meta' => $this->meta,
            'read_at' => $this->read_at,
            'edited_at' => $this->edited_at,
            'deleted_at' => $this->deleted_at,
            'edits' => $this->whenLoaded('edits', fn () => $this->edits->map(fn ($edit) => [
                'old_body' => $edit->old_body,
                'edited_at' => $edit->edited_at,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
