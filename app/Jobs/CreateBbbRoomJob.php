<?php

namespace App\Jobs;

use App\Models\ClassSession;
use App\Services\SessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateBbbRoomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $classSessionId) {}

    public function handle(SessionService $sessionService): void
    {
        $session = ClassSession::find($this->classSessionId);

        if (! $session) {
            return;
        }

        $sessionService->createBbbRoom($session);
    }
}
