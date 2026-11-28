<?php

namespace App\Events\Chat;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** حظر/رفع حظر إداري — يصل لصفحة المحادثة المفتوحة ولقائمة محادثات الطرفين معاً */
class ConversationStatusChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public readonly Conversation $conversation) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('conversation.'.$this->conversation->id),
            new PrivateChannel('user.'.$this->conversation->student_user_id),
            new PrivateChannel('user.'.$this->conversation->provider_user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'status' => $this->conversation->status,
        ];
    }
}
