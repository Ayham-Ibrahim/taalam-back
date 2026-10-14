<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * تُرسَل فور تسجيل طلب تغيير موعد جلسة (RescheduleService::request):
 *  - للطالب والمعلم: تأكيد استلام الطلب وأنه بانتظار مراجعة الأدمن.
 *  - للأدمن ($forReviewer=true): تنبيه بأن هناك طلباً يحتاج قراره الإلزامي (FR-8.1).
 *
 * قبل هذا الإصلاح كان الطلب يُسجَّل في القاعدة فقط دون إبلاغ أي طرف إطلاقاً —
 * لا بريد ولا إشعار على لوحة التحكم — رغم أن موافقة الأدمن إلزامية دائماً.
 */
class RescheduleRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    /**
     * @param  string  $requesterRole  'student' | 'teacher' — الطرف الذي أرسل الطلب فعلياً
     * @param  Carbon|null  $proposedScheduledAt  null = "تبليغ غياب" بلا موعد بديل مقترح —
     *   الطرف لم يقترح وقتاً جديداً، وينتظر أن يُنسّق الأدمن واحداً بعد التواصل المباشر
     */
    public function __construct(
        private readonly Carbon $currentScheduledAt,
        private readonly ?Carbon $proposedScheduledAt,
        private readonly string $requesterRole,
        private readonly ?string $reason = null,
        private readonly bool $forReviewer = false,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        // المخزّن UTC دوماً — يُحوَّل لتوقيت المستلم نفسه قبل العرض، وإلا يظهر
        // وقت مختلف عمّا يراه الطرف الآخر (نفس معالجة SessionReminder/Reviewed).
        $timezone = $notifiable->timezone ?? config('app.timezone');
        $current = $this->currentScheduledAt->clone()->setTimezone($timezone);
        $proposed = $this->proposedScheduledAt?->clone()->setTimezone($timezone);
        $isAbsenceReport = $proposed === null;

        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if ($this->forReviewer) {
            $mail->subject($isAbsenceReport ? 'Session Absence Reported — Awaiting Your Review' : 'New Reschedule Request Awaiting Your Review')
                ->line($isAbsenceReport
                    ? "The {$this->requesterRole} has reported they cannot attend this session and requires admin coordination."
                    : "A session reschedule request has been submitted by the {$this->requesterRole} and requires admin approval.");
        } else {
            $mail->subject($isAbsenceReport ? 'Your Absence Was Reported' : 'Your Reschedule Request Was Submitted')
                ->line($isAbsenceReport
                    ? 'Your report has been recorded and the administration will reach out to coordinate a replacement.'
                    : 'Your request to reschedule the session has been recorded and is now awaiting admin review.')
                ->line('You will be notified once a decision is made.');
        }

        $mail->line("Current time: {$current->format('H:i')} on {$current->format('Y-m-d')} ({$current->format('e')} time).");

        if ($proposed) {
            $mail->line("Proposed time: {$proposed->format('H:i')} on {$proposed->format('Y-m-d')} ({$proposed->format('e')} time).");
        } else {
            $mail->line('No alternative time was proposed — the administration will arrange one directly.');
        }

        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [
            'forReviewer' => $this->forReviewer,
            'requesterRole' => $this->requesterRole,
            'currentScheduledAt' => $this->currentScheduledAt->toIso8601String(),
            'proposedScheduledAt' => $this->proposedScheduledAt?->toIso8601String(),
            'reason' => $this->reason,
        ];
    }
}
