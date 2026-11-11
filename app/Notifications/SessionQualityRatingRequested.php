<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Models\SessionQualityRating;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** للطالب عند بدء الجلسة ودخوله ودخول المعلم: "من فضلك قيّم الجلسة" مع رابط صفحة تقييم الجودة */
class SessionQualityRatingRequested extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly ClassSession $session) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Please rate your session')
            ->greeting("Hello {$notifiable->name},")
            ->line('Please take a minute to rate your session — your feedback helps us keep session quality high.')
            ->action('Rate the session', frontend_url(SessionQualityRating::frontendPath($this->session->ensureQualityRatingToken())))
            ->line('Your answers are visible to the platform management only.');
    }

    public function toArray($notifiable): array
    {
        return [
            'class_session_id' => $this->session->id,
            'scheduled_at' => $this->session->scheduled_at->toIso8601String(),
            'link' => SessionQualityRating::frontendPath($this->session->ensureQualityRatingToken()),
        ];
    }
}
