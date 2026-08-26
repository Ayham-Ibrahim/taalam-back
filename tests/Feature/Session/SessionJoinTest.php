<?php

namespace Tests\Feature\Session;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BigBlueButtonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /class-sessions/{session}/join كان مفقوداً تماماً — الفرونت إند كان
 * يفتح رابط BBB الخام مباشرة، وهذا يُظهر استجابة XML غير منسّقة في المتصفح
 * كلما لم يكن الاجتماع يعمل فعلياً على BBB (السر غير مضبوط في .env محلياً،
 * الجلسة لم تبدأ بعد، أو انتهت). هذا الـ endpoint يتحقق أولاً ثم يُرجع إما
 * الرابط الحقيقي أو رسالة عربية واضحة.
 */
class SessionJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_join_returns_the_real_url_when_the_bbb_room_already_exists(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subMinutes(5));

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldReceive('meetingExists')->once()->andReturn(true);
        });

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(200);
        $response->assertJsonPath('data.url', $session->join_url_student);
    }

    /**
     * التراجع الحرج الذي يحميه هذا الاختبار: BBB يُرجع isMeetingRunning=false
     * لأي اجتماع لم ينضم إليه أحد بعد حتى لو أُنشئ بنجاح تام — فلو استُخدمت
     * كبوّابة سماح بالانضمام (كما كان الحال سابقاً)، لن يستطيع أول شخص
     * (طالب أو معلم) الانضمام إلى أي جلسة إطلاقاً رغم أنها مؤكدة ومدفوعة
     * وحان موعدها فعلاً. الفحص الصحيح (meetingExists عبر getMeetingInfo)
     * يُرجع true للغرفة الموجودة بصرف النظر عن انضمام أحد إليها من قبل.
     */
    public function test_the_first_person_to_join_a_session_is_not_blocked_just_because_nobody_has_joined_yet(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subMinutes(5));

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldReceive('meetingExists')->once()->andReturn(true);
        });

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(200);
        $response->assertJsonPath('data.url', $session->join_url_student);
    }

    public function test_join_returns_a_clean_arabic_error_instead_of_raw_bbb_xml_when_the_session_has_not_started_yet(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->addHour());

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(422);
        $this->assertStringContainsString('لم تبدأ', $response->json('message'));
    }

    public function test_join_reports_the_session_has_ended_when_past_its_window(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subHours(3));

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(422);
        $this->assertStringContainsString('انتهت', $response->json('message'));
    }

    public function test_join_reports_a_clean_error_when_the_room_does_not_actually_exist_on_bbb_within_the_valid_window(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subMinutes(5));

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldReceive('meetingExists')->once()->andReturn(false);
            // resolveJoinUrl يحاول إعادة إنشاء الغرفة قبل الاستسلام (انظر
            // الاختبار الآخر أدناه) — هنا تفشل المحاولة أيضاً فيبقى الرفض قائماً.
            $mock->shouldReceive('createMeeting')->once()->andReturn(false);
        });

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(422);
        $this->assertStringContainsString('تعذّر الوصول', $response->json('message'));
    }

    /**
     * الغرفة قد لا تكون أُنشئت فعلاً على BBB من الأساس (بيانات اعتماد BBB لم
     * تكن مضبوطة بعد وقت جدولة الجلسة، أو فشل استدعاء create وقتها) —
     * createBbbRoom لا يُعيد المحاولة أبداً لجلسة معها bbb_meeting_id محفوظ
     * سلفاً، فتبقى الجلسة محظورة الانضمام إلى الأبد حتى لو أُصلح إعداد BBB
     * لاحقاً. resolveJoinUrl يعالج هذا بمحاولة إنشاء نفس الغرفة مرة أخيرة هنا.
     */
    public function test_join_self_heals_by_recreating_the_room_when_it_was_never_actually_created_on_bbb(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subMinutes(5));

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldReceive('meetingExists')->once()->andReturn(false);
            $mock->shouldReceive('createMeeting')->once()->andReturn(true);
        });

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(200);
        $response->assertJsonPath('data.url', $session->join_url_student);
    }

    /**
     * جلسة قديمة جداً بلا كلمتَي مرور BBB محفوظتين (مسار بيانات هامشي) يجب أن
     * تسقط مباشرة لرسالة الفشل الواضحة بدل استدعاء createMeeting بمعاملات
     * ناقصة، الذي كان سيُطلق TypeError صريحاً (استجابة 500 غير مفهومة للمستخدم).
     */
    public function test_join_skips_the_recreate_attempt_and_fails_cleanly_when_bbb_passwords_were_never_stored(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subMinutes(5));
        $session->update(['bbb_attendee_pw' => null, 'bbb_moderator_pw' => null]);

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldReceive('meetingExists')->once()->andReturn(false);
            $mock->shouldNotReceive('createMeeting');
        });

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(422);
        $this->assertStringContainsString('تعذّر الوصول', $response->json('message'));
    }

    public function test_the_teacher_gets_their_own_join_url_not_the_students(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session] = $this->createBookingWithSession($teacher, now()->subMinutes(5));

        $this->mock(BigBlueButtonService::class, function ($mock) {
            $mock->shouldReceive('meetingExists')->once()->andReturn(true);
        });

        $response = $this->as($teacherUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(200);
        $response->assertJsonPath('data.url', $session->join_url_teacher);
    }

    public function test_a_student_not_attending_the_session_cannot_join(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $session] = $this->createBookingWithSession($teacher, now()->subMinutes(5));

        $outsiderUser = User::factory()->student()->create();
        Student::create(['user_id' => $outsiderUser->id, 'education_type' => 'school']);

        $response = $this->as($outsiderUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(403);
    }

    public function test_join_fails_cleanly_when_no_join_url_was_ever_set(): void
    {
        [$teacher, $teacherUser] = $this->createVerifiedTeacher();
        [$booking, $session, $studentUser] = $this->createBookingWithSession($teacher, now()->subMinutes(5), withJoinUrls: false);

        $response = $this->as($studentUser->createToken('t')->plainTextToken)
            ->getJson("/api/class-sessions/{$session->id}/join");

        $response->assertStatus(422);
        $this->assertNotNull($response->json('message'));
    }

    /**
     * @return array{0: Teacher, 1: User}
     */
    private function createVerifiedTeacher(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        return [$teacher, $teacherUser];
    }

    /**
     * @return array{0: Booking, 1: ClassSession, 2: User}
     */
    private function createBookingWithSession(Teacher $teacher, Carbon $scheduledAt, bool $withJoinUrls = true): array
    {
        $subject = Subject::create(['code' => 'sj-'.uniqid(), 'name_ar' => 'مادة']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 1,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 1,
            'sessions_remaining' => 1,
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'scheduled',
            'bbb_meeting_id' => $withJoinUrls ? (string) \Illuminate\Support\Str::uuid() : null,
            'bbb_attendee_pw' => $withJoinUrls ? 'attendee-pw' : null,
            'bbb_moderator_pw' => $withJoinUrls ? 'moderator-pw' : null,
            'join_url_teacher' => $withJoinUrls ? 'https://meet.example/teacher-x' : null,
            'join_url_student' => $withJoinUrls ? 'https://meet.example/student-x' : null,
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return [$booking, $session, $studentUser];
    }
}
