<?php

namespace App\Jobs;

use App\Services\SessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * الحالة الوحيدة التي تجعل جلسة "منتهية" فعلياً لبقية النظام (المستحقات
 * المالية، التقييمات، منع تغيير الموعد) — بلا هذا الـ job تبقى كل الجلسات
 * scheduled إلى الأبد حتى بعد مرور موعدها بأيام. راجع SessionService::completeEndedSessions.
 */
class CompleteEndedSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SessionService $sessionService): void
    {
        $sessionService->completeEndedSessions();
    }
}
