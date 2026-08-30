<?php

namespace App\Jobs;

use App\Services\SessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * فحص دوري لجلسات انتهت ولم يُعثر على رابط تسجيلها بعد — BBB يستغرق وقتاً
 * لمعالجة التسجيل بعد انتهاء الاجتماع، فلا يمكن الاعتماد على webhook فوري؛
 * الاستقصاء الدوري هو الأسلوب المعتمد هنا (راجع SessionService::fetchAvailableRecordings).
 */
class FetchSessionRecordingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SessionService $sessionService): void
    {
        $sessionService->fetchAvailableRecordings();
    }
}
