<?php

namespace App\Jobs;

use App\Services\SubscriptionEndingSoonNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * كل 5 دقائق: الاشتراكات التي بقيت لها جلسة واحدة يصل الأدمن إشعار بها (جرس + بريد) للتواصل
 * مع الطالب للتجديد. راجع SubscriptionEndingSoonNotificationService.
 */
class SendSubscriptionEndingSoonNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SubscriptionEndingSoonNotificationService $service): void
    {
        $service->sendPending();
    }
}
