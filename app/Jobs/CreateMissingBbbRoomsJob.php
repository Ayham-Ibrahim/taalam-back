<?php

namespace App\Jobs;

use App\Services\SessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * شبكة أمان لأي جلسة مدفوعة قادمة بقيت بلا غرفة BBB (وبالتالي بلا رابط انضمام
 * ولا تذكير) — راجع SessionService::createMissingRooms.
 */
class CreateMissingBbbRoomsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SessionService $sessionService): void
    {
        $sessionService->createMissingRooms();
    }
}
