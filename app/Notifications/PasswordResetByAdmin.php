<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * يوازي AccountCreatedByAdmin تماماً (نفس سبب استبعاد قناة database — كلمة
 * المرور نص صريح هنا) لكن لحساب موجود مسبقاً يعيد الأدمن ضبط كلمة مروره،
 * لا حساباً جديداً.
 */
class PasswordResetByAdmin extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly string $plainPassword) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        if ($notifiable instanceof User && $notifiable->isSiblingAccount()) {
            return $this->siblingMail($notifiable);
        }

        return (new MailMessage)
            ->subject('Your Taalam Password Has Been Reset')
            ->greeting("Hello {$notifiable->name},")
            ->line('An administrator has reset your account password. Here are your new login details:')
            ->line("**Email:** {$notifiable->email}")
            ->line("**Password:** {$this->plainPassword}")
            ->action('Log In to Taalam', frontend_url('/login'))
            ->line('For your security, we recommend changing your password after logging in.');
    }

    /**
     * حساب أخ بلا إيميل — الرسالة تصل لبريد الحساب الأساسي (contactEmail)،
     * فتُوجَّه لصاحبه وتوضّح أن كلمة المرور لحساب الأخ لا لحسابه هو.
     */
    private function siblingMail(User $sibling): MailMessage
    {
        $primary = $sibling->primaryAccount;

        return (new MailMessage)
            ->subject("Password Reset for {$sibling->name}'s Taalam Account")
            ->greeting('Hello '.($primary?->name ?? $sibling->name).',')
            ->line("An administrator has reset the password of your sibling's account: **{$sibling->name}**.")
            ->line("This is not your own password. {$sibling->name} logs in with your email and this new password:")
            ->line("**Email:** {$sibling->contactEmail()}")
            ->line("**Password:** {$this->plainPassword}")
            ->action('Log In to Taalam', frontend_url('/login'))
            ->line('You can change this password anytime from the Siblings section in your account settings.');
    }
}
