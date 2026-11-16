<?php

namespace App\Jobs;

use App\Services\RescheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * يصعّد للأدمن طلبات تغيير الموعد التي انقضت مهلة رد الطرف الآخر عليها بلا رد
 * (counterparty_deadline). مجدول كل دقيقة (routes/console.php).
 */
class EscalateRescheduleRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RescheduleService $rescheduleService): void
    {
        $rescheduleService->escalateTimedOut();
    }
}
