<?php

namespace App\Notifications;

use App\Models\Message;
use App\Services\PresenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * إشعار database للمستلم غير المتصل — بلا بريد إطلاقاً. يُدفع متأخراً (MessageService)،
 * ولا يُرسَل إن قرأ المستلم الرسالة خلال المهلة، أو حُذفت، أو تلتها رسالة أحدث من نفس
 * المرسل (تلك سيُشعر بها إشعارها هي، فتصل دفعة الرسائل كإشعار واحد)، أو كان المستلم
 * متصلاً بالمنصة لحظتها: وصلته الرسالة لحظياً مع toast عبر قناته الخاصة.
 * لا يمر عبر NotificationService عمداً: الإشعار المتخطّى كان سيترك سطر notification_logs
 * عالقاً على queued للأبد.
 */
class NewChatMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Message $message) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function shouldSend($notifiable, string $channel): bool
    {
        $message = Message::find($this->message->id);

        if (! $message || $message->read_at !== null) {
            return false;
        }

        $hasNewerFromSender = Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('sender_id', $message->sender_id)
            ->where('id', '>', $message->id)
            ->exists();

        return ! $hasNewerFromSender && ! app(PresenceService::class)->isOnline($notifiable->id);
    }

    public function toArray($notifiable): array
    {
        return [
            'conversationId' => $this->message->conversation_id,
            'messageId' => $this->message->id,
            'senderId' => $this->message->sender_id,
            'senderName' => $this->message->sender?->name,
            'preview' => mb_substr($this->message->body, 0, 100),
        ];
    }
}
