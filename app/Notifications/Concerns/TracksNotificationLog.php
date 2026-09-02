<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationLog;
use Throwable;

/**
 * تسمح لـ NotificationService بربط كل قناة إرسال بسطر notification_logs مطابق،
 * لتحديث حالته (sent) عند استقبال حدث NotificationSent لاحقاً.
 */
trait TracksNotificationLog
{
    /** @var array<string,int> channel => notification_logs.id */
    public array $notificationLogIds = [];

    /**
     * Illuminate\Notifications\SendQueuedNotifications::failed() يستدعي هذه
     * الدالة تلقائياً (إن وُجدت) بعد استنفاد كل محاولات إعادة الإرسال — قبل
     * هذا الإصلاح لم يكن شيء يحدّث notification_logs عند الفشل إطلاقاً، فيبقى
     * السطر عالقاً على "queued" للأبد بلا أي فرق ظاهر عن مهمة لم تُعالَج بعد،
     * ولا رسالة الخطأ الفعلية (SMTP مرفوض، دومين غير موثّق...) محفوظة في مكان
     * يراه الأدمن. لا نُعيد كتابة سطر نجح فعلاً على قناة أخرى لنفس الإشعار.
     */
    public function failed(Throwable $exception): void
    {
        if (empty($this->notificationLogIds)) {
            return;
        }

        NotificationLog::whereIn('id', array_values($this->notificationLogIds))
            ->where('status', 'queued')
            ->update(['status' => 'failed', 'error' => $exception->getMessage()]);
    }
}
