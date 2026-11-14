<?php

namespace App\Notifications;

use App\Models\Package;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** إبلاغ المعلم بأن الأدمن أضاف باسمه باقة حملة مخفضة — طلبات حجزها تحتاج موافقته كالمعتاد */
class CampaignPackageAssigned extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly Package $package) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A Campaign Package Was Added to Your Profile')
            ->greeting("Hello {$notifiable->name},")
            ->line("The administration added a campaign package to your profile: \"{$this->package->title}\" ({$this->package->sessions_count} individual sessions).")
            ->line('It is visible only to students who joined through the campaign. Booking requests need your approval as usual.')
            ->line('Sessions of this package are covered by your fixed monthly salary and are not included in per-session payouts.')
            ->action('Open the platform', frontend_url('/'));
    }

    public function toArray($notifiable): array
    {
        return [
            'package_id' => $this->package->id,
            'title' => $this->package->title,
            'type' => 'campaign_package_assigned',
        ];
    }
}
