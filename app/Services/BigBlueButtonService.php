<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for the BigBlueButton API (create + signed join URLs).
 * Every call degrades gracefully to `false`/a best-effort URL instead of
 * throwing — a BBB outage or missing credentials must never break the
 * booking/session lifecycle (queued jobs would otherwise fail-and-retry
 * forever against a server that simply isn't configured yet).
 */
class BigBlueButtonService
{
    public function createMeeting(string $meetingId, string $meetingName, string $attendeePw, string $moderatorPw): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(10)->get($this->signedUrl('create', [
                'name' => $meetingName,
                'meetingID' => $meetingId,
                'attendeePW' => $attendeePw,
                'moderatorPW' => $moderatorPw,
                'record' => 'false',
                'duration' => '0',
            ]));

            $xml = $response->ok() ? @simplexml_load_string($response->body()) : null;
            $success = $xml !== false && $xml !== null && (string) $xml->returncode === 'SUCCESS';

            if (! $success) {
                Log::warning('bbb.create_meeting_failed', [
                    'meetingId' => $meetingId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return $success;
        } catch (Throwable $e) {
            Log::warning('bbb.create_meeting_exception', ['meetingId' => $meetingId, 'message' => $e->getMessage()]);

            return false;
        }
    }

    public function buildJoinUrl(string $meetingId, string $password, string $fullName = 'Guest'): string
    {
        return $this->signedUrl('join', [
            'meetingID' => $meetingId,
            'fullName' => $fullName,
            'password' => $password,
        ]);
    }

    private function isConfigured(): bool
    {
        return filled(config('services.bbb.url')) && filled(config('services.bbb.secret'));
    }

    private function signedUrl(string $callName, array $params): string
    {
        $baseUrl = rtrim((string) (config('services.bbb.url') ?: 'https://bbb.example.com'), '/');
        $secret = (string) config('services.bbb.secret');
        $queryString = http_build_query($params);
        $checksum = hash($this->algorithm(), $callName.$queryString.$secret);

        return "{$baseUrl}/{$callName}?{$queryString}&checksum={$checksum}";
    }

    private function algorithm(): string
    {
        return config('services.bbb.checksum_algorithm', 'sha1');
    }
}
