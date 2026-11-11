<?php

namespace App\Notifications;

use App\Models\ClassSession;
use App\Models\SessionQualityRating;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * للمعلم عند بدء الجلسة ودخول الطالب: "شكراً لك، تم إرسال رابط تقييم الجودة إلى الطالب" + نفس الرابط،
 * كي يستطيع إرساله للطالب بنفسه خارج المنصة أيضاً.
 */
class SessionQualityRatingLinkSent extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly ClassSession $session) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $link = frontend_url(SessionQualityRating::frontendPath($this->session->ensureQualityRatingToken()));

        return (new MailMessage)
            ->subject('Thank you — the session quality rating link was sent to your student')
            ->greeting("Hello {$notifiable->name},")
            ->line('Thank you for your session. The quality rating link has been sent to your student.')
            ->line('You can also share this link with your student directly:')
            ->line($link);
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
