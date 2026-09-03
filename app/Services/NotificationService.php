<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * البوابة الوحيدة لإرسال الإشعارات. كل إشعار يُسجَّل مسبقاً في notification_logs
 * (حالة queued) قبل أن يُدفع إلى طابور المعالجة — UpdateNotificationLogStatus
 * يحدّث الحالة إلى sent عند نجاح الإرسال الفعلي.
 */
class NotificationService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function send(mixed $notifiable, Notification $notification, string $event, ?int $delaySeconds = null): void
    {
        $notification = $this->prepare($notifiable, $notification, $event);

        if ($delaySeconds !== null && $delaySeconds > 0 && method_exists($notification, 'delay')) {
            $notification->delay($delaySeconds);
        }

        $notifiable->notify($notification);
    }

    /**
     * تأخير خطّي (بالثواني) لعنصر ترتيبه $index ضمن استيراد جماعي — كل عنصر
     * يبعد عن سابقه bulk_notification_stagger_seconds ثانية (إعداد قابل
     * للتعديل من لوحة الأدمن، نفس نمط student_import_max_rows)، فيوزَّع أي
     * حجم استيراد عبر ساعات بدل دفعة واحدة فورية. الحادثة الحقيقية التي دفعت
     * لهذا: استيراد 1500+ سجل دفع كل بريده الترحيبي للطابور فوراً، فعالجه
     * الـ worker بأقصى سرعته الفعلية خلال ساعة واحدة — نمط burst كبير من
     * دومين واحد فعّل حماية Mailtrap تلقائياً وعلّق الحساب (bounce rate).
     * راجع StudentImportService/TeacherImportService لموقع الاستخدام.
     */
    public function bulkDelaySeconds(int $index): int
    {
        $staggerSeconds = max(0, (int) $this->settings->get('bulk_notification_stagger_seconds', 8));

        return $index * $staggerSeconds;
    }

    /**
     * إرسال فوري متزامن (يتجاوز طابور jobs) — للاستخدام داخل Job مجدولة أصلاً
     * وقابلة لإعادة المحاولة بنفسها (مثل SendSessionRemindersJob). دفع إشعار
     * ShouldQueue عبر send() هناك يعني اعتماد قفزة طابور إضافية غير مراقَبة:
     * إن لم يعالجها worker حتى النهاية (أو فشلت لأي طرف بصمت)، لا نعرف أبداً
     * ولا تُعاد المحاولة — فيصل التذكير لطرف (المعلم مثلاً) دون الآخر بلا أي أثر.
     * sendNow ينفّذ الإرسال هنا مباشرة فيسمح لنا بالتقاط الفشل وتسجيله فوراً.
     *
     * @throws \Throwable عند فشل الإرسال الفعلي — على المستدعي التقاطه إن أراد
     *                      الاستمرار لمستلمين آخرين بدل إيقاف العملية بالكامل
     */
    public function sendNow(mixed $notifiable, Notification $notification, string $event): void
    {
        NotificationFacade::sendNow($notifiable, $this->prepare($notifiable, $notification, $event));
    }

    private function prepare(mixed $notifiable, Notification $notification, string $event): Notification
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

        return $notification;
    }
}
