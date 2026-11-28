<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /**
     * المرسل وحده، رسالة نصية غير محذوفة. مهلة EDIT_WINDOW_MINUTES وحالة المحادثة يفحصهما
     * MessageService::assertModifiable برسالة واضحة (422) بدل 403 عام.
     */
    public function update(User $user, Message $message): bool
    {
        return (int) $message->sender_id === $user->id
            && ! $message->isSystem()
            && ! $message->trashed();
    }

    public function delete(User $user, Message $message): bool
    {
        return $this->update($user, $message);
    }

    /** الإبلاغ عن رسالة الطرف الآخر فقط (لا رسائل النظام ولا رسائل المُبلِّغ نفسه) */
    public function report(User $user, Message $message): bool
    {
        return ! $user->isAdmin()
            && ! $message->isSystem()
            && (int) $message->sender_id !== $user->id
            && $message->conversation->hasParticipant($user);
    }
}
