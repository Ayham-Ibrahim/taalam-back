<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * الأدمن يضع كلمة المرور مباشرة بدل رابط دعوة يضعها المستخدم بنفسه (RULE:
 * دخول فوري بلا خطوة "قبول دعوة" منفصلة). قناة database مستبعدة عمداً — كلمة
 * المرور نص صريح هنا ولا يصح تخزينها ضمن جدول notifications الدائم.
 */
class AccountCreatedByAdmin extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly string $role,
        private readonly string $plainPassword,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $roleLabel = $this->role === 'teacher' ? 'teacher' : 'student';

        return (new MailMessage)
            ->subject('Your Taalam Account Has Been Created')
            ->greeting("Hello {$notifiable->name},")
            ->line("Our team has created a {$roleLabel} account for you on Taalam. Here are your login details:")
            ->line("**Email:** {$notifiable->email}")
            ->line("**Password:** {$this->plainPassword}")
            ->action('Log In to Taalam', frontend_url('/login'))
            ->line('For your security, we recommend changing your password after your first login.');
    }
}
