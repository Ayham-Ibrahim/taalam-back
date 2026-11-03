<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * يرسل حدثاً واحداً جاهزاً (بناه MetaConversionsService) إلى Meta Conversions API.
 * في الطابور كي لا ينتظر الطالب ولا يتأخر رد webhook Stripe، مع إعادة محاولة عند الفشل.
 */
class SendMetaConversionEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly array $payload) {}

    public function handle(): void
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            config('services.meta.graph_version'),
            config('services.meta.pixel_id'),
        );

        Http::timeout(10)
            ->post($url, [...$this->payload, 'access_token' => config('services.meta.access_token')])
            ->throw();
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Meta Conversions API event failed', [
            'event_name' => $this->payload['data'][0]['event_name'] ?? null,
            'event_id' => $this->payload['data'][0]['event_id'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
