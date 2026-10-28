<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * تُرسَل للمعلم ولكل طلاب الجلسة حين يعدّل الأدمن موعدها مباشرة (بلا طلب تغيير
 * موعد مسبق) — راجع RescheduleService::adminReschedule. رابط الانضمام نفسه لا
 * يتغيّر، لذا تكتفي بإبلاغ الموعد الجديد.
 */
class SessionRescheduledByAdmin extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly Carbon $previousScheduledAt,
        private readonly Carbon $newScheduledAt,
        private readonly ?string $notes = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        // مخزّنة UTC دوماً — تُحوَّل لتوقيت المستلم نفسه قبل العرض.
        $timezone = $notifiable->timezone ?? config('app.timezone');
        $previous = $this->previousScheduledAt->clone()->setTimezone($timezone);
        $new = $this->newScheduledAt->clone()->setTimezone($timezone);

        $mail = (new MailMessage)
            ->subject('Session Time Changed')
            ->greeting("Hello {$notifiable->name},")
            ->line('The administration has changed the time of one of your sessions.')
            ->line("Previous time: {$previous->format('H:i')} on {$previous->format('Y-m-d')} ({$previous->format('e')} time).")
            ->line("New time: {$new->format('H:i')} on {$new->format('Y-m-d')} ({$new->format('e')} time).")
            ->line('Your session join link stays the same.');

        if ($this->notes) {
            $mail->line('Note from the administration: '.$this->notes);
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [
            'previousScheduledAt' => $this->previousScheduledAt->toIso8601String(),
            'newScheduledAt' => $this->newScheduledAt->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
