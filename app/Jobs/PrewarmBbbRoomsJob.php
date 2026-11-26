<?php

namespace App\Jobs;

use App\Services\SessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * تجهيز غرف BBB للجلسات القريبة قبل موعدها — راجع SessionService::prewarmUpcomingRooms.
 *
 * يُجدوَل كل دقيقة على worker الطابور الوحيد: ShouldBeUnique يمنع تراكم نسخه خلف بعضها
 * حين يتعلّق BBB فيطول التشغيل (فتتأخر التذكيرات وإنهاء الجلسات خلفها). uniqueFor يحرّر
 * القفل وحده إن قُتل الـ worker أثناء التشغيل (--timeout) فلا يتوقف التجهيز للأبد.
 * لا إعادة محاولة: الدورة التالية بعد دقيقة هي المحاولة التالية أصلاً.
 */
class PrewarmBbbRoomsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function handle(SessionService $sessionService): void
    {
        $sessionService->prewarmUpcomingRooms();
    }
}
