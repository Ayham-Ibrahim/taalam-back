<?php

namespace App\Services;

use App\Events\Chat\ChatUpdated;
use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageSent;
use App\Events\Chat\MessagesRead;
use App\Events\Chat\MessageUpdated;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewChatMessage;
use App\Traits\BroadcastsAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الإرسال والتعديل والحذف عبر HTTP ثم البث عبر WebSocket. الصلاحية (طرف في المحادثة،
 * ملكية الرسالة) في ConversationPolicy/MessagePolicy، والفلترة في NoContactInfo — هنا
 * شروط الحالة والوقت (المحظورة، مهلة الـ 30 دقيقة) كي تصل برسالة واضحة (422) لا 403 عام.
 */
class MessageService
{
    use BroadcastsAfterCommit;

    /**
     * إشعار database للمستلم غير المتصل بعد هذه المدة إن بقيت الرسالة غير مقروءة —
     * NewChatMessage::shouldSend يتخطاه إن كان المستلم متصلاً بالمنصة لحظتها.
     */
    private const OFFLINE_NOTIFICATION_DELAY_SECONDS = 60;

    public function __construct(private readonly ConversationService $conversations) {}

    public function send(Conversation $conversation, User $sender, string $body): Message
    {
        $this->assertActive($conversation, 'لا يمكن الإرسال في محادثة محظورة');

        $recipient = $conversation->otherParticipant($sender);
        if (! $recipient || $recipient->trashed() || ! $recipient->is_active) {
            throw ValidationException::withMessages(['body' => ['الطرف الآخر غير متاح حالياً']]);
        }

        // حساب ظل لزائر الحصة المجانية (بلا كلمة مرور ولا دخول): المحادثة تُفتح بتأكيد حجزه
        // كي تبقى بعد تسجيله (يتحوّل نفس الحساب)، لكن لا أحد يقرأ ما يُرسَل إليه قبل ذلك
        if ($recipient->isGuestAccount()) {
            throw ValidationException::withMessages(['body' => ['لا يمكن مراسلة هذا الطالب قبل أن يُنشئ حسابه في المنصة']]);
        }

        $message = DB::transaction(function () use ($conversation, $sender, $body) {
            $message = $conversation->messages()->create([
                'sender_id' => $sender->id,
                'type' => Message::TYPE_TEXT,
                'body' => $body,
            ]);

            $conversation->update(['last_message_at' => $message->created_at]);

            return $message;
        });

        $this->broadcastAfterCommit(new MessageSent($message));
        $this->broadcastAfterCommit(new ChatUpdated(
            $recipient->id,
            $conversation->id,
            $this->conversations->unreadTotal($recipient->id),
            MessageResource::preview($message),
        ), toOthers: false);

        // إشعار واحد لكل دفعة رسائل متتالية — NewChatMessage::shouldSend يتخطى الإشعار إن
        // قُرئت الرسالة خلال المهلة أو تلتها رسالة أحدث من نفس المرسل (هي من سيُشعر).
        $recipient->notify((new NewChatMessage($message))->delay(self::OFFLINE_NOTIFICATION_DELAY_SECONDS));

        return $message;
    }

    public function update(Message $message, string $body): Message
    {
        $this->assertModifiable($message, 'انتهت مهلة تعديل الرسالة (30 دقيقة من إرسالها)');

        DB::transaction(function () use ($message, $body) {
            $message->edits()->create([
                'old_body' => $message->body,
                'edited_at' => now(),
            ]);

            $message->update(['body' => $body, 'edited_at' => now()]);
        });

        $this->broadcastAfterCommit(new MessageUpdated($message));

        return $message->fresh();
    }

    public function delete(Message $message): Message
    {
        $this->assertModifiable($message, 'انتهت مهلة حذف الرسالة (30 دقيقة من إرسالها)');

        $message->delete();

        $this->broadcastAfterCommit(new MessageDeleted($message));

        $conversation = $message->conversation;
        $recipientId = $conversation->otherParticipantId((int) $message->sender_id);
        $this->broadcastAfterCommit(new ChatUpdated($recipientId, $conversation->id, $this->conversations->unreadTotal($recipientId)), toOthers: false);

        return $message;
    }

    /**
     * يعلّم كل رسائل الطرف الآخر غير المقروءة — مسموح حتى في المحادثة المحظورة (قراءة السجل).
     * read_at يُعاد للفرونت كي يعلّم نسخته المحلية بنفس الحد (رسائل حتى هذه اللحظة فقط):
     * حدث MessagesRead يصل للطرف الآخر لا للقارئ نفسه.
     *
     * @return array{marked: int, read_at: string}
     */
    public function markRead(Conversation $conversation, User $reader): array
    {
        $readAt = now();

        $count = $conversation->messages()
            ->where('type', Message::TYPE_TEXT)
            ->where('sender_id', '!=', $reader->id)
            ->whereNull('read_at')
            ->where('created_at', '<=', $readAt)
            ->update(['read_at' => $readAt]);

        if ($count > 0) {
            $this->broadcastAfterCommit(new MessagesRead($conversation->id, $reader->id, $readAt->toIso8601String()));
            $this->broadcastAfterCommit(new ChatUpdated($reader->id, $conversation->id, $this->conversations->unreadTotal($reader->id)), toOthers: false);
        }

        return ['marked' => $count, 'read_at' => $readAt->toIso8601String()];
    }

    private function assertActive(Conversation $conversation, string $message): void
    {
        if (! $conversation->isActive()) {
            throw ValidationException::withMessages(['conversation' => [$message]]);
        }
    }

    private function assertModifiable(Message $message, string $expiredMessage): void
    {
        $this->assertActive($message->conversation, 'لا يمكن تعديل الرسائل أو حذفها في محادثة محظورة');

        if (! $message->isWithinEditWindow()) {
            throw ValidationException::withMessages(['body' => [$expiredMessage]]);
        }
    }
}
