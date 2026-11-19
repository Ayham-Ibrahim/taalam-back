<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * للمعلم: طالب أرسل طلب حجز باقة فردية بانتظار موافقته — راجع BookingService::requestIndividualBooking.
 * الطلب يبطل تلقائياً إن حلّ أول موعد مطلوب دون موافقة ($deadline)، فبلا هذا الإشعار لا يعلم به
 * المعلم إلا إن فتح لوحته.
 */
class BookingRequestReceived extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly int $bookingId,
        private readonly string $studentName,
        private readonly string $packageTitle,
        private readonly int $sessionsCount,
        private readonly Carbon $deadline,
    ) {
        // قد يُستدعى داخل معاملة أوسع (حجز الزائر التجريبي) — لا يُرسل لطلب تراجعت معاملته
        $this->afterCommit();
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        // مخزّنة UTC دوماً — تُحوَّل لتوقيت المستلم نفسه قبل العرض
        $deadline = $this->deadline->clone()->setTimezone($notifiable->timezone ?? config('app.timezone'));

        return (new MailMessage)
            ->subject('New Booking Request — Your Approval Is Needed')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->studentName} has requested to book your package \"{$this->packageTitle}\" ({$this->sessionsCount} sessions).")
            ->line("The first requested session is at {$deadline->format('H:i')} on {$deadline->format('Y-m-d')} ({$deadline->format('e')} time).")
            ->line('If the request is not approved before that time, it is cancelled automatically.')
            ->action('Review the request', frontend_url('/dashboard/teacher/booking-requests'));
    }

    public function toArray($notifiable): array
    {
        return [
            'bookingId' => $this->bookingId,
            'studentName' => $this->studentName,
            'packageTitle' => $this->packageTitle,
            'sessionsCount' => $this->sessionsCount,
            'deadline' => $this->deadline->toIso8601String(),
        ];
    }
}
