<?php

namespace App\Notifications;

use App\Models\AccountInvitation;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherInvited extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly AccountInvitation $invitation) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're Invited to Join Taalam as a Teacher")
            ->greeting("Hello {$notifiable->name},")
            ->line("You've been invited to join Taalam as a teacher.")
            ->action('Activate Your Account', url('/invitations/'.$this->invitation->token))
            ->line('This invitation link expires soon — please activate your account as soon as possible.');
    }

    public function toArray($notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'expires_at' => $this->invitation->expires_at->toIso8601String(),
        ];
    }
}
