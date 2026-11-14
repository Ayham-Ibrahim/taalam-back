<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * الزائر (حساب ظل بلا دخول) لا يرى حالة طلبه في المنصة — كل مرحلة تصله بالبريد:
 * استلام الطلب، ثم موافقة المعلم (بالموعد) أو رفضه. رابط الانضمام يصله لاحقاً مع
 * تذكير الجلسة (SendSessionRemindersJob) كأي طالب.
 */
class GuestTrialBookingStatus extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public const RECEIVED = 'received';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        private readonly Booking $booking,
        private readonly string $status,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $timezone = $notifiable->timezone ?? config('app.timezone');
        $teacherName = $this->booking->loadMissing('teacher.user')->teacher?->user?->name;
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if ($this->status === self::REJECTED) {
            return $mail->subject('Your Free Session Request Was Declined')
                ->line("Unfortunately, {$teacherName} could not accept your free session request at the requested time.")
                ->when($this->booking->cancellation_reason, fn (MailMessage $m) => $m->line('Reason: '.$this->booking->cancellation_reason))
                ->line('You can book another time from the teacher page.')
                ->action('Visit the platform', frontend_url('/'));
        }

        if ($this->status === self::APPROVED) {
            $mail->subject('Your Free Session Is Confirmed')
                ->line("{$teacherName} has confirmed your free session.");

            foreach ($this->booking->loadMissing('sessions')->sessions as $session) {
                /** @var ClassSession $session */
                $local = $session->scheduled_at->clone()->setTimezone($timezone);
                $mail->line("Session time: {$local->format('Y-m-d H:i')} ({$local->format('e')} time).");
            }

            return $mail
                ->line('We will email you the link to join the session shortly before it starts.')
                ->line('Policies: '.frontend_url('/student-policy'));
        }

        $mail->subject('We Received Your Free Session Request')
            ->line("Your free session request with {$teacherName} has been sent to the teacher for confirmation.");

        foreach ($this->booking->requested_slots ?? [] as $slot) {
            $local = Carbon::parse($slot['date'], $this->booking->requested_timezone ?? $timezone)
                ->setTimeFromTimeString($slot['start_time']);
            $mail->line("Requested time: {$local->format('Y-m-d H:i')} ({$local->format('e')} time).");
        }

        return $mail->line('We will email you as soon as the teacher responds.');
    }
}
