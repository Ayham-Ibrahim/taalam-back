<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherVerificationReviewed extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly bool $approved,
        private readonly ?string $reason = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        return $this->approved
            ? $mail->subject('Your Account Has Been Verified')
                ->line('Congratulations! Your account verification has been approved — you can now create your packages.')
            : $mail->subject('Your Verification Request Was Declined')
                ->line("We're sorry, your account verification request was declined.")
                ->line('Reason: '.$this->reason);
    }

    public function toArray($notifiable): array
    {
        return [
            'approved' => $this->approved,
            'reason' => $this->reason,
        ];
    }
}
