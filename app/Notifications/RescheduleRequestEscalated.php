<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * الطرف الآخر رفض اقتراح الموعد أو لم يرد قبل المهلة — الطلب أُحيل للإدارة:
 *  - للأدمن ($forReviewer=true): قراره مطلوب الآن.
 *  - لطرفي الجلسة: الطلب لم يُحسم تلقائياً وصار بيد الإدارة.
 */
class RescheduleRequestEscalated extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    /**
     * @param  string  $cause  'declined' | 'timeout'
     */
    public function __construct(
        private readonly string $cause,
        private readonly Carbon $currentScheduledAt,
        private readonly ?Carbon $proposedScheduledAt,
        private readonly string $requesterRole,
        private readonly bool $forReviewer = false,
        // 'reschedule' | 'makeup'
        private readonly string $kind = 'reschedule',
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $timezone = $notifiable->timezone ?? config('app.timezone');
        $current = $this->currentScheduledAt->clone()->setTimezone($timezone);
        $proposed = $this->proposedScheduledAt?->clone()->setTimezone($timezone);
        $causeLine = $this->cause === 'declined'
            ? 'The other party declined the proposed time.'
            : 'The other party did not respond in time.';

        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");
        $what = $this->kind === 'makeup' ? 'Makeup Session Request' : 'Reschedule Request';

        if ($this->forReviewer) {
            $mail->subject("{$what} Escalated — Awaiting Your Decision")
                ->line('A '.strtolower($what)." submitted by the {$this->requesterRole} needs your decision.")
                ->line($causeLine);
        } else {
            $mail->subject("{$what} Forwarded to the Administration")
                ->line($causeLine)
                ->line('The request has been forwarded to the administration, and you will be notified of its decision.');
        }

        $mail->line("Current time: {$current->format('H:i')} on {$current->format('Y-m-d')} ({$current->format('e')} time).");

        if ($proposed) {
            $mail->line("Proposed time: {$proposed->format('H:i')} on {$proposed->format('Y-m-d')} ({$proposed->format('e')} time).");
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [
            'cause' => $this->cause,
            'forReviewer' => $this->forReviewer,
            'requesterRole' => $this->requesterRole,
            'kind' => $this->kind,
            'currentScheduledAt' => $this->currentScheduledAt->toIso8601String(),
            'proposedScheduledAt' => $this->proposedScheduledAt?->toIso8601String(),
        ];
    }
}
