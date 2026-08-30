<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * تُرسَل لصاحب طلب تغيير الموعد (requester — طالب أو معلم، أيهما أرسل الطلب
 * فعلياً) فور قرار الأدمن، سواء بالموافقة أو الرفض. قبل هذا الإصلاح لم يكن
 * قرار الأدمن يُبلَّغ لأي طرف إطلاقاً — لا بريد ولا إشعار على لوحة التحكم.
 */
class RescheduleRequestReviewed extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    /**
     * @param  string  $status  'approved' | 'approved_with_alternative' | 'rejected'
     */
    public function __construct(
        private readonly string $status,
        private readonly ?Carbon $newScheduledAt = null,
        private readonly ?string $reason = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if ($this->status === 'rejected') {
            // يصل الآن لطرفَي الجلسة معاً (طالب ومعلم)، لا لصاحب الطلب وحده —
            // فالصياغة محايدة لا تفترض أن المستلم هو من قدّم الطلب.
            return $mail->subject('Session Reschedule Request Declined')
                ->line('The request to reschedule this session has been declined by the administration.')
                ->line('The session stays at its original time.')
                ->line('Reason: '.$this->reason);
        }

        // مخزّنة UTC دوماً — تُحوَّل لتوقيت المستلم نفسه هنا قبل العرض، وإلا
        // يظهر وقت مختلف عمّا فُعِّل فعلياً (نفس مشكلة SessionReminder سابقاً).
        $localTime = $this->newScheduledAt?->clone()->setTimezone($notifiable->timezone ?? config('app.timezone'));

        $mail->subject('Your Reschedule Request Was Approved')
            ->line('Good news! Your request to reschedule the session has been approved.');

        if ($localTime) {
            $mail->line("New session time: {$localTime->format('H:i')} on {$localTime->format('Y-m-d')} ({$localTime->format('e')} time).");
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [
            'status' => $this->status,
            'newScheduledAt' => $this->newScheduledAt?->toIso8601String(),
            'reason' => $this->reason,
        ];
    }
}
