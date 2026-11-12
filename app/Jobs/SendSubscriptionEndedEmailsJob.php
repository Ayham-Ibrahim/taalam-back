<?php

namespace App\Jobs;

use App\Services\SubscriptionEndedNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * كل 5 دقائق: الاشتراكات التي انتهت آخر جلساتها يصل بريد لحساب الطالب يدعوه للتجديد مع
 * رابط بروفايل المعلم. راجع SubscriptionEndedNotificationService.
 */
class SendSubscriptionEndedEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SubscriptionEndedNotificationService $service): void
    {
        $service->sendPending();
    }
}
