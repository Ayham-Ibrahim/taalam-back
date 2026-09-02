<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * بديل عن Illuminate\Auth\Notifications\ResetPassword الافتراضية — تلك تبني
 * رابطاً لمسار ويب Blade باسم "password.reset" غير موجود إطلاقاً في تطبيق
 * API خالص كهذا (لا واجهة Blade على الإطلاق)؛ هذه تشير مباشرة لصفحة إعادة
 * التعيين في الفرونت اند (React SPA منفصل تماماً عن الباك اند).
 */
class ResetPassword extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = frontend_url('/reset-password?token='.$this->token.'&email='.urlencode($notifiable->email));

        return (new MailMessage)
            ->subject('Reset Your Taalam Password')
            ->greeting("Hello {$notifiable->name},")
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
