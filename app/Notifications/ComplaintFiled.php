<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * تُرسَل لكل أدمن نشط فور تسجيل شكوى جديدة (ComplaintService::file) — سواء
 * جاءت من طالب أو معلم. قبل هذا الإصلاح كانت الشكوى تُسجَّل بصمت تام، ولا
 * يعرف أي أدمن بوجودها إلا بفتح لوحة الشكاوى يدوياً (نفس ثغرة طلبات تغيير
 * الموعد قبل RescheduleRequestSubmitted).
 */
class ComplaintFiled extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    /**
     * @param  string  $filerRole  'student' | 'teacher' — من قدّم الشكوى فعلياً
     */
    public function __construct(
        private readonly string $reference,
        private readonly string $category,
        private readonly string $filerRole,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->greeting("Hello {$notifiable->name},")
            ->subject('New Complaint Filed — Review Required')
            ->line("A new complaint ({$this->reference}) has been filed by a {$this->filerRole}.")
            ->line("Category: {$this->category}")
            ->line('Please review it from the admin complaints dashboard.');
    }

    public function toArray($notifiable): array
    {
        return [
            'reference' => $this->reference,
            'category' => $this->category,
            'filerRole' => $this->filerRole,
        ];
    }
}
