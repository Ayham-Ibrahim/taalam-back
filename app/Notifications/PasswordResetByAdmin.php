<?php

namespace App\Notifications;

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
        return (new MailMessage)
            ->subject('Your Taalam Password Has Been Reset')
            ->greeting("Hello {$notifiable->name},")
            ->line('An administrator has reset your account password. Here are your new login details:')
            ->line("**Email:** {$notifiable->email}")
            ->line("**Password:** {$this->plainPassword}")
            ->action('Log In to Taalam', url('/login'))
            ->line('For your security, we recommend changing your password after logging in.');
    }
}
