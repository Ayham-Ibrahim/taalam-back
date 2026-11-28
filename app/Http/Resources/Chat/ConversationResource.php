<?php

namespace App\Http\Resources\Chat;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** من منظور المستخدم الحالي: counterpart هو الطرف الآخر أياً كان دوره */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $counterpart = $this->otherParticipant($request->user());

        return [
            'id' => $this->id,
            'status' => $this->status,
            'counterpart' => $counterpart ? self::participant($counterpart) : null,
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage
                ? MessageResource::make($this->latestMessage)->resolve($request)
                : null),
            'unread_count' => (int) ($this->unread_count ?? 0),
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
        ];
    }

    public static function participant(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'avatar_path' => $user->avatar_path,
            // الزائر (حساب ظل بلا دخول) لا يقرأ الرسائل — MessageService::send يرفض مراسلته
            'is_available' => $user->is_active && ! $user->trashed() && ! $user->isGuestAccount(),
        ];
    }
}
