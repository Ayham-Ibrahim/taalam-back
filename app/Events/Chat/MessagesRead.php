<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessagesRead implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $readerId,
        public readonly string $readAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'messages.read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'reader_id' => $this->readerId,
            'read_at' => $this->readAt,
        ];
    }
}
