<?php

namespace App\Http\Resources\Chat;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * عرض الطرفين: نص الرسالة المحذوفة لا يُرسَل إطلاقاً (الإدارة وحدها تراه عبر
 * AdminMessageResource). نفس الشكل يُبث عبر WebSocket، فلا حقول خاصة بالمشاهد هنا —
 * الفرونت يقارن sender_id و editable_until لإظهار أزرار التعديل والحذف.
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDeleted = $this->trashed();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'type' => $this->type,
            'body' => $isDeleted ? null : $this->body,
            'meta' => $isDeleted ? null : $this->meta,
            'is_edited' => $this->edited_at !== null,
            'is_deleted' => $isDeleted,
            'read_at' => $this->read_at,
            'editable_until' => $this->isSystem() ? null : $this->editableUntil(),
            'created_at' => $this->created_at,
        ];
    }

    /** نسخة مختصرة لقائمة المحادثات و toast (ChatUpdated) — حدث WebSocket محدود الحجم */
    public static function preview(Message $message): array
    {
        $data = self::make($message)->resolve();

        if ($data['body'] !== null) {
            $data['body'] = Str::limit($data['body'], 120);
        }

        return $data;
    }
}
