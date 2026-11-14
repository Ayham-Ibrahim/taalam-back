<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * رمز التحقق من بريد الزائر قبل حجز الباقة المجانية بلا حساب — يُرسل لعنوان بريد مباشرة
 * (Notification::route) لا لمستخدم، فلا يمر عبر NotificationService (يحتاج مستخدماً للسجل).
 */
class GuestVerificationCode extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly int $validMinutes,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Verification Code')
            ->greeting('Hello,')
            ->line('Use this code to confirm your email and complete your free session booking:')
            ->line("**{$this->code}**")
            ->line("The code is valid for {$this->validMinutes} minutes.")
            ->line('If you did not request this code, you can safely ignore this email.');
    }
}
