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
            ->subject('دعوة للانضمام كمعلم في منصة تعلّم')
            ->greeting("مرحباً {$notifiable->name}")
            ->line('تمت دعوتك للانضمام كمعلم في منصة تعلّم.')
            ->action('تفعيل الحساب', url('/invitations/'.$this->invitation->token))
            ->line('صلاحية رابط الدعوة محدودة، يرجى تفعيل الحساب في أقرب وقت.');
    }

    public function toArray($notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'expires_at' => $this->invitation->expires_at->toIso8601String(),
        ];
    }
}
