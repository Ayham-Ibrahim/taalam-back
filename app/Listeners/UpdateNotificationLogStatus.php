<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Notifications\Events\NotificationSent;

class UpdateNotificationLogStatus
{
    public function handle(NotificationSent $event): void
    {
        if (! in_array(TracksNotificationLog::class, class_uses_recursive($event->notification), true)) {
            return;
        }

        $logId = $event->notification->notificationLogIds[$event->channel] ?? null;

        if (! $logId) {
            return;
        }

        NotificationLog::whereKey($logId)->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
