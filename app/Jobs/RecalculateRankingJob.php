<?php

namespace App\Jobs;

use App\Models\Teacher;
use App\Services\RankingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateRankingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $teacherId) {}

    public function handle(RankingService $rankingService): void
    {
        $teacher = Teacher::find($this->teacherId);

        if (! $teacher) {
            return;
        }

        $rankingService->recalculate($teacher);
    }
}
