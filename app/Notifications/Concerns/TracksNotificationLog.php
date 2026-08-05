<?php

namespace App\Notifications\Concerns;

/**
 * تسمح لـ NotificationService بربط كل قناة إرسال بسطر notification_logs مطابق،
 * لتحديث حالته (sent) عند استقبال حدث NotificationSent لاحقاً.
 */
trait TracksNotificationLog
{
    /** @var array<string,int> channel => notification_logs.id */
    public array $notificationLogIds = [];
}
