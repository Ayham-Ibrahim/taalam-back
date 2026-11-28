<?php

namespace App\Services;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Throwable;

/**
 * "متصل بالمنصة" = مشترك الآن في قناته الخاصة private-user.{id} — الفرونت (ChatRealtimeBridge)
 * يشترك فيها ما دام مسجّلاً في أي صفحة. مصدر واحد لمعنى "متصل" في كل النظام: مؤشر رأس
 * المحادثة (ConversationController::presence) وتخطّي إشعار database (NewChatMessage).
 * بلا Reverb أو عند تعذّر السؤال: غير متصل (مهلة الاتصال قصيرة — config/broadcasting.php).
 */
class PresenceService
{
    public function isOnline(int $userId): bool
    {
        try {
            $broadcaster = Broadcast::driver();
            if (! $broadcaster instanceof PusherBroadcaster) {
                return false;
            }

            return (bool) ($broadcaster->getPusher()->getChannelInfo('private-user.'.$userId)->occupied ?? false);
        } catch (Throwable) {
            return false;
        }
    }
}
