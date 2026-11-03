<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * تُرسَل للمعلم ولكل طلاب الجلسة حين ينشئ الأدمن جلسة تعويضية لجلسة غاب عنها المعلم
 * أو الطلاب — راجع SessionService::scheduleMakeupByAdmin.
 */
class MakeupSessionScheduled extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly Carbon $missedScheduledAt,
        private readonly Carbon $makeupScheduledAt,
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
        $missed = $this->missedScheduledAt->clone()->setTimezone($timezone);
        $makeup = $this->makeupScheduledAt->clone()->setTimezone($timezone);

        $mail = (new MailMessage)
            ->subject('Makeup Session Scheduled')
            ->greeting("Hello {$notifiable->name},")
            ->line("The administration has scheduled a makeup session for the session of {$missed->format('Y-m-d')} at {$missed->format('H:i')}.")
            ->line("Makeup session time: {$makeup->format('H:i')} on {$makeup->format('Y-m-d')} ({$makeup->format('e')} time).")
            ->line('You can join it from your sessions list once it starts.');

        if ($this->notes) {
            $mail->line('Note from the administration: '.$this->notes);
        }

        return $mail;
    }

    public function toArray($notifiable): array
    {
        return [
            'missedScheduledAt' => $this->missedScheduledAt->toIso8601String(),
            'makeupScheduledAt' => $this->makeupScheduledAt->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
