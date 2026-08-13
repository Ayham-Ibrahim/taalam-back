<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionReminder extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly ClassSession $session,
        private readonly string $joinUrl,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        // scheduled_at مخزّنة بتوقيت الخادم (UTC) دائماً — يجب تحويلها لتوقيت
        // المستلم نفسه (مدرس أو طالب، كل بمنطقته) قبل العرض، وإلا يظهر وقت
        // مختلف تماماً عمّا اتفق عليه الطرفان فعلياً عند الحجز (نفس المشكلة
        // التي عولجت سابقاً في الحجز والجدولة — هنا فقط لم تُطبَّق في البريد).
        $localTime = $this->session->scheduled_at->clone()->setTimezone($notifiable->timezone ?? config('app.timezone'));

        return (new MailMessage)
            ->subject('Reminder: Your Session Starts Soon')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your session is scheduled to start at {$localTime->format('H:i')} on {$localTime->format('Y-m-d')} ({$localTime->format('e')} time).")
            ->action('Join Session', $this->joinUrl)
            ->line('Please join a few minutes early to make sure your connection and audio are ready.');
    }

    public function toArray($notifiable): array
    {
        return [
            'class_session_id' => $this->session->id,
            'scheduled_at' => $this->session->scheduled_at->toIso8601String(),
        ];
    }
}
