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

    /**
     * "يعمل" بمعنى BBB الحرفي: انضم إليه أحد فعلاً على الأقل. لا تصلح كبوّابة
     * سماح بالانضمام — أول شخص يحاول الدخول (طالب أو معلم) يجدها دوماً false
     * لأن لا أحد انضمّ بعد، فتُحظَر أول محاولة انضمام إلى الأبد. استُخدمت هكذا
     * خطأً سابقاً في resolveJoinUrl، انظر meetingExists() للبديل الصحيح.
     */
    public function isMeetingRunning(string $meetingId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(10)->get($this->signedUrl('isMeetingRunning', [
                'meetingID' => $meetingId,
            ]));

            $xml = $response->ok() ? @simplexml_load_string($response->body()) : null;

            return $xml !== false && $xml !== null
                && (string) $xml->returncode === 'SUCCESS'
                && (string) $xml->running === 'true';
        } catch (Throwable $e) {
            Log::warning('bbb.is_meeting_running_exception', ['meetingId' => $meetingId, 'message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * قبل فتح رابط الانضمام الخام (الذي يُرجع XML غير منسّق مباشرةً في
     * المتصفح عند الفشل — اجتماع لم يُنشأ بعد على BBB، أو انتهى)، يجب
     * التحقق أن الغرفة مُنشأة فعلاً على خادم BBB. لا نستطيع "التقاط" فشل
     * التنقّل المباشر لرابط join بعد فتحه — لذا التحقق يجب أن يحدث هنا أولاً.
     *
     * عمداً getMeetingInfo لا isMeetingRunning: الأخيرة تُرجع "running=false"
     * لأي اجتماع لم ينضم إليه أحد بعد حتى لو أُنشئ بنجاح تام — فتمنع أول
     * انضمام إلى الأبد (لا أحد يستطيع أن يكون "الأول" إن كان الانضمام نفسه
     * مشروطاً بوجود شخص منضمّ مسبقاً). getMeetingInfo تُرجع returncode=FAILED
     * فقط حين يكون الاجتماع غير موجود فعلياً على الخادم (SUCCESS بصرف النظر
     * عن عدد المشاركين الحاليين، صفراً أو أكثر).
     */
    public function meetingExists(string $meetingId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(10)->get($this->signedUrl('getMeetingInfo', [
                'meetingID' => $meetingId,
            ]));

            $xml = $response->ok() ? @simplexml_load_string($response->body()) : null;

            return $xml !== false && $xml !== null && (string) $xml->returncode === 'SUCCESS';
        } catch (Throwable $e) {
            Log::warning('bbb.get_meeting_info_exception', ['meetingId' => $meetingId, 'message' => $e->getMessage()]);

            return false;
        }
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
