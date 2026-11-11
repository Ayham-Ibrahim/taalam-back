<?php

namespace App\Jobs;

use App\Services\SessionQualityRatingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * كل دقيقة: عند بدء الجلسة ودخول المعلم والطالب إليها يُرسَل للطالب رابط تقييم جودتها، وللمعلم
 * إشعار شكر بنفس الرابط. راجع SessionQualityRatingService::sendPendingRequests.
 */
class SendSessionQualityRatingRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SessionQualityRatingService $service): void
    {
        $service->sendPendingRequests();
    }
}
