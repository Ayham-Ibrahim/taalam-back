<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * تُرسَل لطرفَي الجلسة معاً (الطالب والمعلم) فور قرار الأدمن، سواء بالموافقة أو
 * الرفض — لا لصاحب الطلب (requester) وحده: الطرف الآخر (الذي لم يُرسل الطلب)
 * جلسته هو أيضاً تتأثر بالقرار فعلياً (موعدها يتغير أو يبقى كما هو)، فلا معنى
 * لحجب الخبر عنه. قبل هذا الإصلاح لم تكن الموافقة تُبلَّغ إلا لصاحب الطلب فقط.
 */
class RescheduleRequestReviewed extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    /**
     * @param  string  $status  'approved' | 'approved_with_alternative' | 'rejected'
     * @param  string|null  $reason  سبب الرفض — rejected فقط
     * @param  string|null  $adminNotes  ملاحظة الأدمن الحرة عند الموافقة (admin_notes) — قد تحمل
     *   ملخّص التنسيق الذي تم مباشرة مع الطرف الآخر خارج المنصة (مكالمة هاتفية مثلاً)
     */
    public function __construct(
        private readonly string $status,
        private readonly ?Carbon $newScheduledAt = null,
        private readonly ?string $reason = null,
        private readonly ?string $adminNotes = null,
        // المسار التلقائي: الطرف الآخر وافق مباشرة، لا الإدارة
        private readonly bool $byCounterparty = false,
        // 'reschedule' | 'makeup' — طلب موعد جلسة تعويضية يُرفض بنفس الإشعار
        private readonly string $kind = 'reschedule',
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if ($this->status === 'rejected' && $this->kind === 'makeup') {
            return $mail->subject('Makeup Session Request Declined')
                ->line('The proposed time for the makeup session has been declined by the administration.')
                ->line('Reason: '.$this->reason);
        }

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

        $mail->subject('Session Reschedule Confirmed')
            ->line($this->byCounterparty
                ? 'The session reschedule has been accepted by the other party and is now confirmed.'
                : 'The session reschedule has been reviewed and confirmed by the administration.');

        if ($localTime) {
            $mail->line("New session time: {$localTime->format('H:i')} on {$localTime->format('Y-m-d')} ({$localTime->format('e')} time).");
        }

        if ($this->adminNotes) {
            $mail->line('Note from the administration: '.$this->adminNotes);
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [
            'status' => $this->status,
            'newScheduledAt' => $this->newScheduledAt?->toIso8601String(),
            'reason' => $this->reason,
            'adminNotes' => $this->adminNotes,
            'byCounterparty' => $this->byCounterparty,
            'kind' => $this->kind,
        ];
    }
}
