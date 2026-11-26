<?php

namespace Tests\Unit;

use App\Services\BigBlueButtonService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * getRecordingUrls() يفسّر XML الحقيقي من BBB — لا يكفي محاكاة هذه الخدمة في
 * اختبارات SessionService (انظر tests/Feature/Session/FetchSessionRecordingsTest.php)؛
 * هذا يتحقق من التفسير نفسه (published فقط، تفضيل تنسيق presentation، تجاهل هادئ للأعطال).
 */
class BigBlueButtonServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bbb.url' => 'https://bbb.example.com/bigbluebutton/api',
            'services.bbb.secret' => 'secret',
            'services.bbb.checksum_algorithm' => 'sha1',
        ]);
    }

    public function test_it_parses_a_published_presentation_recording_and_prefers_it_over_other_formats(): void
    {
        $xml = '<response><returncode>SUCCESS</returncode><recordings>'
            .'<recording><recordID>rec-1</recordID><meetingID>meeting-a</meetingID><published>true</published>'
            .'<playback><format><type>video</type><url>https://meet.example/video/rec-1</url></format>'
            .'<format><type>presentation</type><url>https://meet.example/presentation/rec-1</url></format></playback>'
            .'</recording></recordings></response>';

        Http::fake(['*getRecordings*' => Http::response($xml, 200)]);

        $urls = app(BigBlueButtonService::class)->getRecordingUrls(['meeting-a']);

        $this->assertSame(['meeting-a' => 'https://meet.example/presentation/rec-1'], $urls);
    }

    /**
     * سبب البلاغ: "زر مشاهدة الجلسة يحمّل PDF بدل فتح الفيديو" — تسجيل نُشر
     * فعلاً لكن بلا أي تنسيق playback من نوع presentation (مثال واقعي: BBB
     * ينشئ فقط تنسيق notes حين تُستخدم اللوحة المشتركة، وكثيراً ما يُخدَّم
     * كملف PDF ثابت). $format كان يبقى قائمة SimpleXML الخام لكل التنسيقات
     * حين لا يوجد presentation، و(string) $format->url على قائمة SimpleXML
     * يُرجع صامتاً رابط أول عنصر فيها — فيُحفظ رابط PDF/فيديو خام في
     * recording_url دون أي تحذير، ويفتحه الفرونت إند كرابط تنزيل مباشر.
     */
    public function test_it_skips_a_recording_when_no_presentation_format_is_available(): void
    {
        $xml = '<response><returncode>SUCCESS</returncode><recordings>'
            .'<recording><recordID>rec-4</recordID><meetingID>meeting-d</meetingID><published>true</published>'
            .'<playback><format><type>notes</type><url>https://meet.example/notes/rec-4.pdf</url></format></playback>'
            .'</recording></recordings></response>';

        Http::fake(['*getRecordings*' => Http::response($xml, 200)]);

        $urls = app(BigBlueButtonService::class)->getRecordingUrls(['meeting-d']);

        $this->assertSame([], $urls);
    }

    public function test_it_skips_a_recording_that_is_not_published_yet(): void
    {
        $xml = '<response><returncode>SUCCESS</returncode><recordings>'
            .'<recording><recordID>rec-2</recordID><meetingID>meeting-b</meetingID><published>false</published>'
            .'</recording></recordings></response>';

        Http::fake(['*getRecordings*' => Http::response($xml, 200)]);

        $urls = app(BigBlueButtonService::class)->getRecordingUrls(['meeting-b']);

        $this->assertSame([], $urls);
    }

    public function test_it_returns_multiple_recordings_from_a_single_batched_call(): void
    {
        $xml = '<response><returncode>SUCCESS</returncode><recordings>'
            .'<recording><recordID>rec-1</recordID><meetingID>meeting-a</meetingID><published>true</published>'
            .'<playback><format><type>presentation</type><url>https://meet.example/presentation/rec-1</url></format></playback></recording>'
            .'<recording><recordID>rec-2</recordID><meetingID>meeting-c</meetingID><published>true</published>'
            .'<playback><format><type>presentation</type><url>https://meet.example/presentation/rec-2</url></format></playback></recording>'
            .'</recordings></response>';

        Http::fake(['*getRecordings*' => Http::response($xml, 200)]);

        $urls = app(BigBlueButtonService::class)->getRecordingUrls(['meeting-a', 'meeting-c']);

        $this->assertSame([
            'meeting-a' => 'https://meet.example/presentation/rec-1',
            'meeting-c' => 'https://meet.example/presentation/rec-2',
        ], $urls);
    }

    public function test_it_returns_empty_array_when_bbb_returns_no_recordings_at_all(): void
    {
        $xml = '<response><returncode>SUCCESS</returncode><recordings></recordings></response>';

        Http::fake(['*getRecordings*' => Http::response($xml, 200)]);

        $urls = app(BigBlueButtonService::class)->getRecordingUrls(['meeting-a']);

        $this->assertSame([], $urls);
    }

    public function test_it_returns_empty_array_when_bbb_is_not_configured(): void
    {
        config(['services.bbb.url' => null, 'services.bbb.secret' => null]);

        $urls = app(BigBlueButtonService::class)->getRecordingUrls(['meeting-a']);

        $this->assertSame([], $urls);
    }

    public function test_it_returns_empty_array_for_an_empty_meeting_id_list_without_calling_bbb(): void
    {
        Http::fake(['*getRecordings*' => Http::response('should not be called', 500)]);

        $urls = app(BigBlueButtonService::class)->getRecordingUrls([]);

        $this->assertSame([], $urls);
        Http::assertNothingSent();
    }

    public function test_it_returns_empty_array_when_bbb_request_fails(): void
    {
        Http::fake(['*getRecordings*' => Http::response('', 500)]);

        $urls = app(BigBlueButtonService::class)->getRecordingUrls(['meeting-a']);

        $this->assertSame([], $urls);
    }

    public function test_meeting_exists_treats_an_ended_meeting_still_held_by_bbb_as_missing(): void
    {
        Http::fake(['*getMeetingInfo*' => Http::response(
            '<response><returncode>SUCCESS</returncode><running>false</running><hasBeenForciblyEnded>false</hasBeenForciblyEnded><endTime>1700000000000</endTime></response>',
            200,
        )]);

        $this->assertFalse(app(BigBlueButtonService::class)->meetingExists('meeting-a'));
    }

    public function test_meeting_exists_accepts_a_created_meeting_nobody_has_joined_yet(): void
    {
        Http::fake(['*getMeetingInfo*' => Http::response(
            '<response><returncode>SUCCESS</returncode><running>false</running><hasBeenForciblyEnded>false</hasBeenForciblyEnded><endTime>0</endTime></response>',
            200,
        )]);

        $this->assertTrue(app(BigBlueButtonService::class)->meetingExists('meeting-a'));
    }

    public function test_create_meeting_sends_expiry_params_and_confirms_the_room_is_ready(): void
    {
        Http::fake([
            '*getMeetingInfo*' => Http::response('<response><returncode>SUCCESS</returncode><endTime>0</endTime></response>', 200),
            '*create*' => Http::response('<response><returncode>SUCCESS</returncode></response>', 200),
        ]);

        $this->assertTrue(app(BigBlueButtonService::class)->createMeeting('meeting-a', 'Session', 'ap', 'mp', 60, 60));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/create?')
            && str_contains($request->url(), 'meetingExpireIfNoUserJoinedInMinutes=60')
            && str_contains($request->url(), 'meetingExpireWhenLastUserLeftInMinutes=10'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/getMeetingInfo?'));
    }

    public function test_create_meeting_omits_the_no_user_expiry_when_not_requested(): void
    {
        Http::fake([
            '*getMeetingInfo*' => Http::response('<response><returncode>SUCCESS</returncode><endTime>0</endTime></response>', 200),
            '*create*' => Http::response('<response><returncode>SUCCESS</returncode></response>', 200),
        ]);

        app(BigBlueButtonService::class)->createMeeting('meeting-a', 'Session', 'ap', 'mp', 60);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'meetingExpireIfNoUserJoinedInMinutes'));
    }

    public function test_is_reachable_checks_the_api_root_without_a_checksum(): void
    {
        Http::fake(['https://bbb.example.com/bigbluebutton/api' => Http::response('<response><returncode>SUCCESS</returncode><version>2.0</version></response>', 200)]);

        $this->assertTrue(app(BigBlueButtonService::class)->isReachable());
        Http::assertSent(fn ($request) => $request->url() === 'https://bbb.example.com/bigbluebutton/api');
    }

    public function test_is_reachable_is_false_when_bbb_errors_or_times_out(): void
    {
        Http::fake(['*' => Http::response('Bad Gateway', 502)]);
        $this->assertFalse(app(BigBlueButtonService::class)->isReachable());

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));
        $this->assertFalse(app(BigBlueButtonService::class)->isReachable());
    }
}
