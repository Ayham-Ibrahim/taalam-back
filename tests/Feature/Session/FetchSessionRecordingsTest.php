<?php

namespace Tests\Feature\Session;

use App\Models\ClassSession;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BigBlueButtonService;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BBB يستغرق وقتاً لمعالجة التسجيل بعد انتهاء الاجتماع، فلا يمكن الاعتماد على
 * webhook فوري — SessionService::fetchAvailableRecordings (تُستدعى دورياً عبر
 * FetchSessionRecordingsJob) تستقصي BBB عن أي جلسة منتهية بلا رابط تسجيل بعد.
 */
class FetchSessionRecordingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_the_recording_url_for_an_ended_session_once_bbb_reports_it_published(): void
    {
        $teacher = $this->createTeacher();
        $session = $this->createSession($teacher, now()->subHours(2), 'meeting-a');

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldReceive('getRecordingUrls')
                ->once()
                ->with(['meeting-a'])
                ->andReturn(['meeting-a' => 'https://meet.example/playback/meeting-a']);
        });

        $updated = app(SessionService::class)->fetchAvailableRecordings();

        $this->assertSame(1, $updated);
        $this->assertSame('https://meet.example/playback/meeting-a', $session->fresh()->recording_url);
    }

    public function test_it_does_not_check_a_session_that_has_not_ended_yet(): void
    {
        $teacher = $this->createTeacher();
        $this->createSession($teacher, now()->addHour(), 'meeting-b');

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldNotReceive('getRecordingUrls');
        });

        $updated = app(SessionService::class)->fetchAvailableRecordings();

        $this->assertSame(0, $updated);
    }

    public function test_it_does_not_recheck_a_session_that_already_has_a_recording_url(): void
    {
        $teacher = $this->createTeacher();
        $session = $this->createSession($teacher, now()->subHours(2), 'meeting-c');
        $session->update(['recording_url' => 'https://meet.example/playback/already-there']);

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldNotReceive('getRecordingUrls');
        });

        $updated = app(SessionService::class)->fetchAvailableRecordings();

        $this->assertSame(0, $updated);
        $this->assertSame('https://meet.example/playback/already-there', $session->fresh()->recording_url);
    }

    /** تجاوز نافذة البحث (recording_lookup_window_days) — لا معنى لسؤال BBB إلى الأبد عن جلسة قديمة جداً لم يظهر لها تسجيل قط */
    public function test_it_gives_up_on_a_session_older_than_the_lookup_window(): void
    {
        $teacher = $this->createTeacher();
        $this->createSession($teacher, now()->subDays(30), 'meeting-d');

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldNotReceive('getRecordingUrls');
        });

        $updated = app(SessionService::class)->fetchAvailableRecordings();

        $this->assertSame(0, $updated);
    }

    public function test_a_session_is_left_untouched_when_bbb_has_no_recording_for_it_yet(): void
    {
        $teacher = $this->createTeacher();
        $session = $this->createSession($teacher, now()->subHours(2), 'meeting-e');

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldReceive('getRecordingUrls')->once()->andReturn([]);
        });

        $updated = app(SessionService::class)->fetchAvailableRecordings();

        $this->assertSame(0, $updated);
        $this->assertNull($session->fresh()->recording_url);
    }

    private function createTeacher(): Teacher
    {
        $user = User::factory()->teacher()->create();

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);
    }

    private function createSession(Teacher $teacher, \Illuminate\Support\Carbon $scheduledAt, string $bbbMeetingId): ClassSession
    {
        return ClassSession::create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'scheduled',
            'bbb_meeting_id' => $bbbMeetingId,
        ]);
    }
}
