<?php

namespace Tests\Feature\Session;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\SessionJoinLog;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\SessionQualityRatingLinkSent;
use App\Notifications\SessionQualityRatingRequested;
use App\Services\SessionQualityRatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * تقييم جودة الجلسة: طلب تلقائي عند بدء الجلسة ودخول المعلم والطالب إليها (للطالب رابط التقييم،
 * وللمعلم شكر بنفس الرابط)، تقييم الطالب بخمسة معايير + تعليق اختياري، ويظهر للأدمن فقط.
 */
class SessionQualityRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_started_session_notifies_once_both_teacher_and_student_joined(): void
    {
        Notification::fake();
        [$teacher, $booking, $student] = $this->createBooking();
        // جلسة جارية الآن (بدأت قبل 10 دقائق)
        $session = $this->createSession($booking, now()->subMinutes(10), 'scheduled', null, joined: false);
        $service = app(SessionQualityRatingService::class);

        // لم يدخل أحد بعد، ثم دخل المعلم وحده: لا شيء
        $this->assertSame(0, $service->sendPendingRequests());
        $this->join($session, $teacher->user, 'teacher');
        $this->assertSame(0, $service->sendPendingRequests());
        Notification::assertNothingSent();

        // دخل الطالب أيضاً: يُرسَل للطرفين
        $this->join($session, $student->user, 'student', $student->id);
        $sent = $service->sendPendingRequests();

        $this->assertSame(1, $sent);
        Notification::assertSentTo($student->user, SessionQualityRatingRequested::class, function ($n, $channels, $notifiable) use ($session) {
            // رابط برمز عشوائي لا برقم الجلسة
            $link = $n->toArray($notifiable)['link'];

            return str_starts_with($link, '/rate-session/')
                && $link === '/rate-session/'.$session->fresh()->quality_rating_token;
        });
        Notification::assertSentTo($teacher->user, SessionQualityRatingLinkSent::class);
        $this->assertNotNull($session->fresh()->quality_rating_requested_at);

        // لا تكرار في الدقيقة التالية
        $this->assertSame(0, app(SessionQualityRatingService::class)->sendPendingRequests());
        Notification::assertSentToTimes($student->user, SessionQualityRatingRequested::class, 1);
    }

    public function test_sessions_not_started_cancelled_or_too_old_are_skipped(): void
    {
        Notification::fake();
        [, $booking] = $this->createBooking();

        // لم تبدأ بعد، ملغاة، وجلسة قديمة جداً (قبل النشر) — حتى مع دخول الطرفين لا شيء يُرسَل
        $this->createSession($booking, now()->addMinutes(30), 'scheduled', null);
        $this->createSession($booking, now()->subMinutes(10), 'cancelled', null);
        $this->createSession($booking, now()->subDays(3), 'completed', ClassSession::OUTCOME_ATTENDED);

        $this->assertSame(0, app(SessionQualityRatingService::class)->sendPendingRequests());
        Notification::assertNothingSent();
    }

    public function test_group_student_joining_late_gets_the_link_and_teacher_is_thanked_once(): void
    {
        Notification::fake();
        [$teacher, $booking, $first] = $this->createBooking();
        $session = $this->createSession($booking, now()->subMinutes(5), 'scheduled', null);
        $service = app(SessionQualityRatingService::class);

        $second = Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']);
        SessionAttendee::create(['class_session_id' => $session->id, 'student_id' => $second->id, 'booking_id' => $booking->id, 'attendance' => 'registered']);

        $this->assertSame(1, $service->sendPendingRequests());
        Notification::assertNotSentTo($second->user, SessionQualityRatingRequested::class);

        // الطالب الثاني يدخل متأخراً: يصله طلبه، والمعلم لا يُشكَر مرة ثانية
        $this->join($session, $second->user, 'student', $second->id);
        $this->assertSame(1, $service->sendPendingRequests());
        Notification::assertSentTo($second->user, SessionQualityRatingRequested::class);
        Notification::assertSentToTimes($first->user, SessionQualityRatingRequested::class, 1);
        Notification::assertSentToTimes($teacher->user, SessionQualityRatingLinkSent::class, 1);
    }

    public function test_student_rates_the_session_and_admin_sees_it_with_its_context(): void
    {
        [$teacher, $booking, $student] = $this->createBooking();
        $session = $this->createSession($booking, now()->subMinutes(61), 'completed', ClassSession::OUTCOME_ATTENDED, sequence: 2);
        $studentToken = $student->user->createToken('t')->plainTextToken;
        $url = '/api/session-quality/'.$session->ensureQualityRatingToken();

        $this->withToken($studentToken)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.session.packageTitle', 'باقة رياضيات')
            ->assertJsonPath('data.session.packageType', 'individual')
            ->assertJsonPath('data.session.sessionNumber', 2)
            ->assertJsonPath('data.session.subjectName', 'رياضيات')
            ->assertJsonPath('data.rating', null);

        // كل المعايير إلزامية من 1 إلى 5، والتعليق اختياري
        $this->withToken($studentToken)->postJson($url, ['overall_rating' => 6])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audio_video_quality', 'connection_stability', 'teaching_quality', 'overall_rating', 'benefit_level']);

        $payload = [
            'audio_video_quality' => 5,
            'connection_stability' => 4,
            'teaching_quality' => 5,
            'overall_rating' => 4,
            'benefit_level' => 5,
            'comment' => 'جلسة ممتازة',
        ];
        $this->withToken($studentToken)->postJson($url, $payload)->assertOk();
        // إعادة الإرسال تعدّل نفس التقييم لا تكرّره
        $this->withToken($studentToken)->postJson($url, [...$payload, 'overall_rating' => 5])->assertOk();
        $this->assertDatabaseCount('session_quality_ratings', 1);

        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->getJson('/api/session-quality-ratings')
            ->assertOk()
            ->assertJsonPath('data.0.teacherName', $teacher->user->name)
            ->assertJsonPath('data.0.studentName', $student->user->name)
            ->assertJsonPath('data.0.overallRating', 5)
            ->assertJsonPath('data.0.comment', 'جلسة ممتازة')
            ->assertJsonPath('data.0.sessionNumber', 2);

        // للأدمن فقط
        $this->app['auth']->forgetGuards();
        $teacherToken = $teacher->user->createToken('t')->plainTextToken;
        $this->withToken($teacherToken)->getJson('/api/session-quality-ratings')->assertForbidden();

        // زر "نسخ رابط التقييم" عند المعلم: نفس الرابط بالرمز
        $this->withToken($teacherToken)->getJson("/api/class-sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.quality_rating_path', '/rate-session/'.$session->quality_rating_token);

        // رمز غير موجود أو رقم الجلسة نفسه: 404
        $this->app['auth']->forgetGuards();
        $this->withToken($studentToken)->getJson('/api/session-quality/'.str_repeat('x', 48))->assertNotFound();
        $this->withToken($studentToken)->getJson("/api/session-quality/{$session->id}")->assertNotFound();
    }

    public function test_admin_filters_are_cumulative_and_sort_by_average_quality(): void
    {
        // ثلاث جلسات بثلاثة معلمين وطلاب ومواد مختلفة، بمتوسطات 5 و3 و1
        $rows = [];
        foreach ([5, 3, 1] as $score) {
            [$teacher, $booking, $student] = $this->createBooking();
            $session = $this->createSession($booking, now()->subMinutes(61), 'completed', ClassSession::OUTCOME_ATTENDED);
            \App\Models\SessionQualityRating::create([
                'class_session_id' => $session->id,
                'student_id' => $student->id,
                ...array_fill_keys(\App\Models\SessionQualityRating::CRITERIA, $score),
            ]);
            $rows[$score] = [$teacher, $student, $booking->package->subject_id];
        }

        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;
        $ids = fn (string $query) => collect($this->withToken($token)->getJson("/api/session-quality-ratings?{$query}")->assertOk()->json('data'))
            ->pluck('averageScore')->all();

        $this->assertSame([5, 3, 1], $ids('sort=highest'));
        $this->assertSame([1, 3, 5], $ids('sort=lowest'));

        // اسم المعلم / الطالب / المادة — كلٌّ وحده ثم مجتمعة
        $this->assertSame([3], $ids('teacher_name='.urlencode($rows[3][0]->user->name)));
        $this->assertSame([1], $ids('student_name='.urlencode($rows[1][1]->user->name)));
        $this->assertSame([5], $ids('subject_id='.$rows[5][2]));
        $this->assertSame([], $ids('teacher_name='.urlencode($rows[3][0]->user->name).'&subject_id='.$rows[5][2]));

        $response = $this->withToken($token)->getJson('/api/session-quality-ratings');
        $this->assertCount(3, $response->json('additional_data.subjectOptions'));
    }

    public function test_only_an_attending_student_can_rate_an_ended_session(): void
    {
        [, $booking, $student] = $this->createBooking();
        $ended = $this->createSession($booking, now()->subMinutes(61), 'completed', ClassSession::OUTCOME_ATTENDED);
        $upcoming = $this->createSession($booking, now()->addHour(), 'scheduled', null);

        [, , $otherStudent] = $this->createBooking();
        $this->withToken($otherStudent->user->createToken('t')->plainTextToken)
            ->getJson('/api/session-quality/'.$ended->ensureQualityRatingToken())
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($student->user->createToken('t')->plainTextToken)
            ->getJson('/api/session-quality/'.$upcoming->ensureQualityRatingToken())
            ->assertStatus(422);
    }

    /**
     * @return array{0: Teacher, 1: Booking, 2: Student}
     */
    private function createBooking(): array
    {
        $teacher = Teacher::create(['user_id' => User::factory()->teacher()->create()->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $subject = Subject::create(['code' => 'q-'.uniqid(), 'name_ar' => 'رياضيات']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة رياضيات',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 640,
            'platform_revenue' => 240,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $student = Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']);

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 640,
            'teacher_amount' => 400,
            'platform_amount' => 240,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'confirmed',
        ]);

        return [$teacher, $booking, $student];
    }

    private function join(ClassSession $session, User $user, string $role, ?int $studentId = null): void
    {
        SessionJoinLog::create([
            'class_session_id' => $session->id,
            'user_id' => $user->id,
            'role' => $role,
            'student_id' => $studentId,
            'first_joined_at' => now(),
            'last_joined_at' => now(),
            'join_count' => 1,
        ]);
    }

    /** joined: المعلم والطالب دخلا الجلسة (سجل الانضمام) — شرط إرسال الرابط وظهوره */
    private function createSession(Booking $booking, $scheduledAt, string $status, ?string $outcome, int $sequence = 1, bool $joined = true): ClassSession
    {
        $session = ClassSession::create([
            'booking_id' => $booking->id,
            'teacher_id' => $booking->teacher_id,
            'sequence_no' => $sequence,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => $status,
            'attendance_outcome' => $outcome,
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        if ($joined) {
            $this->join($session, $booking->teacher->user, 'teacher');
            $this->join($session, $booking->student->user, 'student', $booking->student_id);
        }

        return $session;
    }
}
