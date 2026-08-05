<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Notifications\Notification;

/**
 * البوابة الوحيدة لإرسال الإشعارات. كل إشعار يُسجَّل مسبقاً في notification_logs
 * (حالة queued) قبل أن يُدفع إلى طابور المعالجة — UpdateNotificationLogStatus
 * يحدّث الحالة إلى sent عند نجاح الإرسال الفعلي.
 */
class NotificationService
{
    public function send(mixed $notifiable, Notification $notification, string $event): void
    {
        $channels = method_exists($notification, 'via') ? $notification->via($notifiable) : [];

        $usesTrait = in_array(TracksNotificationLog::class, class_uses_recursive($notification), true);
        $logIds = [];

        foreach ($channels as $channel) {
            $log = NotificationLog::create([
                'user_id' => $notifiable->getKey(),
                'event' => $event,
                'channel' => $channel === 'mail' ? 'email' : $channel,
                'status' => 'queued',
            ]);

            $logIds[$channel] = $log->id;
        }

        if ($usesTrait) {
            $notification->notificationLogIds = $logIds;
        }

        $notifiable->notify($notification);
    }
}
