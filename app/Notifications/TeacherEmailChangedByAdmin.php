<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * يوازي PasswordResetByAdmin — تبليغ أمني بتغيير بيانات دخول حساسة نفّذها الأدمن
 * لا المعلم نفسه، فيصل للبريد الجديد (المعلم يدخل به الآن) ويذكر القديم للسياق.
 */
class TeacherEmailChangedByAdmin extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly string $oldEmail,
        private readonly string $newEmail,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Taalam Account Email Has Been Changed')
            ->greeting("Hello {$notifiable->name},")
            ->line('An administrator has changed the login email for your Taalam account.')
            ->line("**Previous email:** {$this->oldEmail}")
            ->line("**New email:** {$this->newEmail}")
            ->action('Log In to Taalam', frontend_url('/login'))
            ->line('If you did not expect this change, please contact our support team immediately.');
    }
}
