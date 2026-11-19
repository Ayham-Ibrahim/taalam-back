<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * للأدمن: اشتراك طالب بباقة بقيت له جلسة واحدة — فرصة للتواصل معه للتجديد قبل أن ينتهي.
 * راجع SubscriptionEndingSoonNotificationService.
 */
class SubscriptionEndingSoon extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly int $bookingId,
        private readonly int $studentId,
        private readonly string $studentName,
        private readonly ?string $studentPhone,
        private readonly string $packageTitle,
        private readonly string $teacherName,
        private readonly Carbon $lastSessionAt,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        // مخزّنة UTC دوماً — تُحوَّل لتوقيت المستلم نفسه قبل العرض
        $lastSession = $this->lastSessionAt->clone()->setTimezone($notifiable->timezone ?? config('app.timezone'));

        $mail = (new MailMessage)
            ->subject("Subscription Ending Soon — {$this->studentName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->studentName}'s subscription to \"{$this->packageTitle}\" with {$this->teacherName} has one session left.")
            ->line("The last session is at {$lastSession->format('H:i')} on {$lastSession->format('Y-m-d')} ({$lastSession->format('e')} time).");

        if ($this->studentPhone) {
            $mail->line("Phone: {$this->studentPhone}");
        }

        return $mail
            ->line('This is a good time to reach out about renewing.')
            ->action('Open the student profile', frontend_url("/dashboard/admin/students/{$this->studentId}"));
    }

    public function toArray($notifiable): array
    {
        return [
            'bookingId' => $this->bookingId,
            'studentId' => $this->studentId,
            'studentName' => $this->studentName,
            'studentPhone' => $this->studentPhone,
            'packageTitle' => $this->packageTitle,
            'teacherName' => $this->teacherName,
            'lastSessionAt' => $this->lastSessionAt->toIso8601String(),
        ];
    }
}
