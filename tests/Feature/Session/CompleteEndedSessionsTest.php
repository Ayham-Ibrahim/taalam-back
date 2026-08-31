<?php

namespace Tests\Feature\Session;

use App\Models\ClassSession;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لا شيء آخر في النظام يُحوّل جلسة إلى 'completed' بمجرد انتهاء وقتها — هذا
 * يكسر توليد المستحقات المالية، التقييمات، ومنع تغيير موعد جلسة منتهية.
 * راجع SessionService::completeEndedSessions.
 */
class CompleteEndedSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scheduled_session_past_its_end_time_becomes_completed(): void
    {
        $teacher = $this->createTeacher();
        $session = $this->createSession($teacher, now()->subHours(2), 'scheduled');

        $updated = app(SessionService::class)->completeEndedSessions();

        $this->assertSame(1, $updated);
        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_a_session_still_within_its_time_window_is_left_scheduled(): void
    {
        $teacher = $this->createTeacher();
        // بدأت قبل 10 دقائق، مدتها 60 — لا تزال جارية فعلياً
        $session = $this->createSession($teacher, now()->subMinutes(10), 'scheduled');

        $updated = app(SessionService::class)->completeEndedSessions();

        $this->assertSame(0, $updated);
        $this->assertSame('scheduled', $session->fresh()->status);
    }

    public function test_a_future_session_is_left_scheduled(): void
    {
        $teacher = $this->createTeacher();
        $session = $this->createSession($teacher, now()->addDay(), 'scheduled');

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame('scheduled', $session->fresh()->status);
    }

    /** حالة نهائية استثنائية مسبقة — لا يصح استبدالها بـ completed */
    public function test_a_no_show_teacher_session_is_not_overwritten(): void
    {
        $teacher = $this->createTeacher();
        $session = $this->createSession($teacher, now()->subHours(2), 'no_show_teacher');

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame('no_show_teacher', $session->fresh()->status);
    }

    public function test_a_cancelled_session_is_not_overwritten(): void
    {
        $teacher = $this->createTeacher();
        $session = $this->createSession($teacher, now()->subHours(2), 'cancelled');

        app(SessionService::class)->completeEndedSessions();

        $this->assertSame('cancelled', $session->fresh()->status);
    }

    private function createTeacher(): Teacher
    {
        $user = User::factory()->teacher()->create();

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);
    }

    private function createSession(Teacher $teacher, \Illuminate\Support\Carbon $scheduledAt, string $status): ClassSession
    {
        return ClassSession::create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => $status,
        ]);
    }
}
