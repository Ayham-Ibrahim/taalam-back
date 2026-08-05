<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * الأدمن يضع كلمة المرور مباشرة بدل رابط دعوة يضعها المستخدم بنفسه (RULE:
 * دخول فوري بلا خطوة "قبول دعوة" منفصلة). قناة database مستبعدة عمداً — كلمة
 * المرور نص صريح هنا ولا يصح تخزينها ضمن جدول notifications الدائم.
 */
class AccountCreatedByAdmin extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(
        private readonly string $role,
        private readonly string $plainPassword,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $roleLabel = $this->role === 'teacher' ? 'معلم' : 'طالب';

        return (new MailMessage)
            ->subject('تم إنشاء حسابك على منصة تعلّم')
            ->greeting("مرحباً {$notifiable->name}")
            ->line("قام فريق تعلّم بإنشاء حساب {$roleLabel} لك على المنصة. بيانات الدخول:")
            ->line("البريد الإلكتروني: {$notifiable->email}")
            ->line("كلمة المرور: {$this->plainPassword}")
            ->action('الدخول إلى المنصة', url('/login'))
            ->line('يُنصح بتغيير كلمة المرور بعد أول تسجيل دخول.');
    }
}
