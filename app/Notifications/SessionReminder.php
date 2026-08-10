<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionReminder extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly ClassSession $session,
        private readonly string $joinUrl,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: Your Session Starts Soon')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your session is scheduled to start at {$this->session->scheduled_at->format('H:i')} on {$this->session->scheduled_at->format('Y-m-d')}.")
            ->action('Join Session', $this->joinUrl)
            ->line('Please join a few minutes early to make sure your connection and audio are ready.');
    }

    public function toArray($notifiable): array
    {
        return [
            'class_session_id' => $this->session->id,
            'scheduled_at' => $this->session->scheduled_at->toIso8601String(),
        ];
    }
}
