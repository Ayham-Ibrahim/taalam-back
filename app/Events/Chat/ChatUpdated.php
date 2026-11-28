<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * لقناة المستخدم الخاصة: تحديث قائمة المحادثات وعداد غير المقروء في الهيدر، و toast إن
 * كان في صفحة أخرى (last_message موجود فقط عند وصول رسالة جديدة).
 */
class ChatUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly int $unreadTotal,
        public readonly ?array $lastMessage = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'unread_total' => $this->unreadTotal,
            'last_message' => $this->lastMessage,
        ];
    }
}
