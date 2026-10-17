<?php

namespace App\Notifications;

use App\Models\SupportPackagePurchase;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * تُرسَل للداعم الأكاديمي فور تأكيد دفع طالب جامعي لإحدى باقاته (عبر webhook Stripe) —
 * لا تتبّع نسبة له من السعر (كل الدفع للمنصة)، فقط إعلام بانضمام طالب جديد ليتواصل
 * معه مباشرة خارج النظام.
 */
class SupportPackagePurchaseConfirmed extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly SupportPackagePurchase $purchase) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $studentName = $this->purchase->student->user->name;
        $subjectName = $this->purchase->supportPackage->subject?->name_ar ?? '';

        return (new MailMessage)
            ->subject('اشتراك طالب جديد في باقة الدعم')
            ->greeting("مرحباً {$notifiable->name}،")
            ->line("اشترك الطالب {$studentName} في باقتك ({$subjectName}) وتم تأكيد الدفع.")
            ->line('يرجى التواصل مع الطالب لتنسيق تقديم الخدمة.');
    }

    public function toArray($notifiable): array
    {
        return [
            'purchase_id' => $this->purchase->id,
            'support_package_id' => $this->purchase->support_package_id,
            'student_id' => $this->purchase->student_id,
            'student_name' => $this->purchase->student->user->name,
            'amount' => (float) $this->purchase->amount,
            'currency' => $this->purchase->currency,
        ];
    }
}
