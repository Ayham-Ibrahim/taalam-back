<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * بريد انتهاء اشتراك الطالب بباقة (استُهلكت كل جلساتها) — يدعوه لتجديد الاشتراك مع رابط
 * بروفايل المعلم الذي حجز عنده. يُرسَل لبريد حساب الطالب (لا بريد منفصل لولي الأمر في
 * المنصة)، ولحساب الأخ الأساسي إن كان الطالب حساب أخ بلا بريد.
 */
class BookingSubscriptionEnded extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly Booking $booking) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $this->booking->loadMissing(['package:id,title', 'teacher.user:id,name', 'student.user:id,name']);

        $studentName = $this->booking->student?->user?->name;
        $packageTitle = $this->booking->package?->title;
        $teacherName = $this->booking->teacher?->user?->name;

        return (new MailMessage)
            ->subject('Your package subscription has ended — renew to keep learning')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$studentName}'s subscription to the package \"{$packageTitle}\" with {$teacherName} has ended — all of its sessions have been completed.")
            ->line('To keep the progress going, you can subscribe again to a new package with the same teacher.')
            ->action('Renew with '.($teacherName ?? 'the teacher'), frontend_url("/teacher/{$this->booking->teacher_id}"))
            ->line('Thank you for learning with Taalam.');
    }
}
