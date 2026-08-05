<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherVerificationReviewed extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly bool $approved,
        private readonly ?string $reason = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("مرحباً {$notifiable->name}");

        return $this->approved
            ? $mail->subject('تمت الموافقة على حسابك')
                ->line('تهانينا! تمت الموافقة على توثيق حسابك ويمكنك الآن إنشاء باقاتك.')
            : $mail->subject('تم رفض طلب التوثيق')
                ->line('نأسف، تم رفض طلب توثيق حسابك.')
                ->line('السبب: '.$this->reason);
    }

    public function toArray($notifiable): array
    {
        return [
            'approved' => $this->approved,
            'reason' => $this->reason,
        ];
    }
}
