<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * للطرف الآخر في الجلسة: الطرف الأول اقترح موعداً جديداً وينتظر موافقته أو رفضه قبل
 * المهلة — راجع RescheduleService::request (المسار التلقائي). بلا رد قبلها يُصعَّد للإدارة.
 */
class RescheduleResponseRequested extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly int $sessionId,
        private readonly int $rescheduleRequestId,
        private readonly string $requesterName,
        private readonly string $requesterRole,
        private readonly Carbon $currentScheduledAt,
        private readonly Carbon $proposedScheduledAt,
        private readonly Carbon $deadline,
        private readonly ?string $reason = null,
        // 'reschedule' | 'makeup' — makeup: $currentScheduledAt موعد الجلسة الفائتة
        private readonly string $kind = 'reschedule',
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        // مخزّنة UTC دوماً — تُحوَّل لتوقيت المستلم نفسه قبل العرض
        $timezone = $notifiable->timezone ?? config('app.timezone');
        $current = $this->currentScheduledAt->clone()->setTimezone($timezone);
        $proposed = $this->proposedScheduledAt->clone()->setTimezone($timezone);
        $deadline = $this->deadline->clone()->setTimezone($timezone);

        $isMakeup = $this->kind === 'makeup';

        $mail = (new MailMessage)
            ->subject($isMakeup ? 'Makeup Session Time Proposed — Your Response Is Needed' : 'Session Reschedule Request — Your Response Is Needed')
            ->greeting("Hello {$notifiable->name},")
            ->line($isMakeup
                ? "{$this->requesterName} ({$this->requesterRole}) has proposed a time for the makeup session of a missed session."
                : "{$this->requesterName} ({$this->requesterRole}) has asked to move your session.")
            ->line(($isMakeup ? 'Missed session' : 'Current time').": {$current->format('H:i')} on {$current->format('Y-m-d')} ({$current->format('e')} time).")
            ->line("Proposed time: {$proposed->format('H:i')} on {$proposed->format('Y-m-d')} ({$proposed->format('e')} time).");

        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail
            ->line('Please accept or decline from your sessions page on the platform.')
            ->line("If you do not respond by {$deadline->format('H:i')} on {$deadline->format('Y-m-d')}, the request will be forwarded to the administration.");
    }

    public function toArray($notifiable): array
    {
        return [
            'sessionId' => $this->sessionId,
            'rescheduleRequestId' => $this->rescheduleRequestId,
            'requesterName' => $this->requesterName,
            'requesterRole' => $this->requesterRole,
            'currentScheduledAt' => $this->currentScheduledAt->toIso8601String(),
            'proposedScheduledAt' => $this->proposedScheduledAt->toIso8601String(),
            'deadline' => $this->deadline->toIso8601String(),
            'reason' => $this->reason,
            'kind' => $this->kind,
        ];
    }
}
