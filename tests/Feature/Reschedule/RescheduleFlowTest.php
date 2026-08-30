<?php

namespace Tests\Feature\Reschedule;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\RescheduleRequestReviewed;
use App\Notifications\RescheduleRequestSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RescheduleFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_within_free_window_does_not_require_reason_but_stays_pending(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addHours(5)); // أقل من نافذة 24 ساعة

        $response = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(3)->toDateTimeString(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.within_free_window', true);

        $this->assertDatabaseHas('reschedule_requests', ['class_session_id' => $session->id, 'status' => 'pending']);
    }

    public function test_request_outside_free_window_requires_reason(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5)); // أكثر من نافذة 24 ساعة

        $missingReason = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
        ]);
        $missingReason->assertStatus(422);

        $withReason = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'ظرف طارئ',
        ]);
        $withReason->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.within_free_window', false);
    }

    public function test_admin_approval_updates_session_time_and_marks_approved(): void
    {
        Notification::fake();

        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));
        $proposedAt = now()->addDays(6);

        $create = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => $proposedAt->toDateTimeString(),
            'reason' => 'سبب',
        ]);
        $requestId = $create->json('data.id');

        [, $adminToken] = $this->createAdmin();

        $approve = $this->as($adminToken)->postJson("/api/reschedule-requests/{$requestId}/approve");
        $approve->assertStatus(200)->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('class_sessions', [
            'id' => $session->id,
            'status' => 'scheduled',
        ]);
        $this->assertNotNull($session->fresh()->original_scheduled_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reschedule.approved']);

        // المعلم هو من أرسل الطلب (requester) هنا — هو من يجب أن يُبلَّغ بالقرار،
        // لا الطالب صاحب الجلسة الذي لم يطلب شيئاً في هذا السيناريو.
        Notification::assertSentTo(
            $teacher->user,
            RescheduleRequestReviewed::class,
            fn ($notification) => $notification->toArray($teacher->user)['status'] === 'approved'
                && $notification->toArray($teacher->user)['newScheduledAt'] === $proposedAt->toIso8601String(),
        );
    }

    public function test_admin_can_approve_with_alternative_time(): void
    {
        Notification::fake();

        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));

        $create = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ]);
        $requestId = $create->json('data.id');

        [, $adminToken] = $this->createAdmin();
        $alternative = now()->addDays(7)->toDateTimeString();

        $approve = $this->as($adminToken)->postJson("/api/reschedule-requests/{$requestId}/approve", [
            'alternative_scheduled_at' => $alternative,
        ]);

        $approve->assertStatus(200)->assertJsonPath('data.status', 'approved_with_alternative');
        $this->assertSame(
            Carbon::parse($alternative)->toDateTimeString(),
            $session->fresh()->scheduled_at->toDateTimeString(),
        );

        Notification::assertSentTo(
            $teacher->user,
            RescheduleRequestReviewed::class,
            fn ($notification) => $notification->toArray($teacher->user)['status'] === 'approved_with_alternative'
                && $notification->toArray($teacher->user)['newScheduledAt'] === Carbon::parse($alternative)->toIso8601String(),
        );
    }

    public function test_admin_reject_requires_reason_and_is_audit_logged(): void
    {
        Notification::fake();

        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));

        $create = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ]);
        $requestId = $create->json('data.id');

        [, $adminToken] = $this->createAdmin();

        $missingReason = $this->as($adminToken)->postJson("/api/reschedule-requests/{$requestId}/reject");
        $missingReason->assertStatus(422);

        $reject = $this->as($adminToken)->postJson("/api/reschedule-requests/{$requestId}/reject", [
            'reason' => 'لا يوجد موعد بديل متاح',
        ]);
        $reject->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('audit_logs', ['action' => 'reschedule.rejected']);

        // كان القرار سابقاً يُحفظ في القاعدة بلا أي تبليغ لصاحب الطلب — لا بريد
        // ولا إشعار على لوحة التحكم — فلا يعلم أبداً أن طلبه رُفض.
        Notification::assertSentTo(
            $teacher->user,
            RescheduleRequestReviewed::class,
            fn ($notification) => $notification->toArray($teacher->user)['status'] === 'rejected'
                && $notification->toArray($teacher->user)['reason'] === 'لا يوجد موعد بديل متاح',
        );
    }

    public function test_rejecting_a_request_notifies_both_the_student_and_the_teacher(): void
    {
        Notification::fake();

        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));
        $studentUser = $session->booking->student->user;

        // المعلم هو صاحب الطلب هنا — الطالب هو الطرف الآخر الذي كان لا يُبلَّغ إطلاقاً
        $create = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ]);
        $requestId = $create->json('data.id');

        [, $adminToken] = $this->createAdmin();
        $this->as($adminToken)->postJson("/api/reschedule-requests/{$requestId}/reject", [
            'reason' => 'لا يوجد موعد بديل متاح',
        ])->assertStatus(200)->assertJsonPath('data.status', 'rejected');

        foreach ([$studentUser, $teacher->user] as $party) {
            Notification::assertSentTo(
                $party,
                RescheduleRequestReviewed::class,
                fn ($n) => $n->toArray($party)['status'] === 'rejected'
                    && $n->toArray($party)['reason'] === 'لا يوجد موعد بديل متاح',
            );
        }
    }

    public function test_non_admin_cannot_approve_or_reject(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));

        $create = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ]);
        $requestId = $create->json('data.id');

        $response = $this->as($teacherToken)->postJson("/api/reschedule-requests/{$requestId}/approve");
        $response->assertStatus(403);
    }

    public function test_second_request_is_blocked_while_first_still_pending(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));

        $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب 1',
        ])->assertStatus(201);

        $second = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(8)->toDateTimeString(),
            'reason' => 'سبب 2',
        ]);

        $second->assertStatus(422);
    }

    public function test_admin_list_includes_filled_reschedule_fields_and_created_at(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));
        $proposedAt = now()->addDays(6);

        $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => $proposedAt->toDateTimeString(),
            'reason' => 'سبب',
        ])->assertStatus(201);

        [, $adminToken] = $this->createAdmin();

        $response = $this->as($adminToken)->getJson('/api/reschedule-requests');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.teacherName', $teacher->user()->value('name'))
            ->assertJsonPath('data.0.studentName', $session->booking->student->user->name)
            ->assertJsonPath('data.0.status', 'pending');

        $this->assertSame($session->scheduled_at->toIso8601String(), $response->json('data.0.originalScheduledAt'));
        $this->assertSame($proposedAt->toIso8601String(), $response->json('data.0.proposedScheduledAt'));
        $this->assertNotNull($response->json('data.0.createdAt'));
    }

    /**
     * الأدمن كان يرى تواريخ طلب تغيير الموعد بتوقيت متصفحه المحلي هو (عبر
     * formatDateTime الافتراضية في الواجهة، بلا أي منطقة زمنية صريحة) — لا
     * علاقة له بجدول الطالب الفعلي. هنا نثبت أن الباك اند يُرجع الآن
     * studentTimezone الصحيحة (منطقة الطالب هو تحديداً)، مختلفة عمداً عن
     * منطقتي المعلم والأدمن كليهما، فلا يمكن أن يمر الاختبار بالصدفة.
     */
    public function test_admin_list_returns_the_students_own_timezone_not_the_admins_or_teachers(): void
    {
        $teacherUser = User::factory()->teacher()->create(['timezone' => 'Europe/London']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);

        $subject = Subject::create(['code' => 'rs-'.uniqid(), 'name_ar' => 'مادة']);
        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $studentUser = User::factory()->student()->create(['timezone' => 'Asia/Tokyo']);
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
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => now()->addDays(5),
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);
        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        $studentToken = $studentUser->createToken('t')->plainTextToken;
        $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ])->assertStatus(201);

        $admin = User::factory()->admin()->create(['timezone' => 'UTC']);
        $adminToken = $admin->createToken('t')->plainTextToken;

        $response = $this->as($adminToken)->getJson('/api/reschedule-requests');

        $response->assertStatus(200)->assertJsonPath('data.0.studentTimezone', 'Asia/Tokyo');
    }

    public function test_student_attending_the_session_can_request_reschedule(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        SessionAttendee::create(['class_session_id' => $session->id, 'student_id' => $student->id, 'attendance' => 'registered']);
        $studentToken = $studentUser->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ]);

        $response->assertStatus(201);
    }

    public function test_submitting_a_request_notifies_the_student_the_teacher_and_every_admin(): void
    {
        Notification::fake();

        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));
        $studentUser = $session->booking->student->user;

        [$admin1] = $this->createAdmin();
        [$admin2] = $this->createAdmin();
        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);

        $proposedAt = now()->addDays(6);
        $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => $proposedAt->toDateTimeString(),
            'reason' => 'ظرف طارئ',
        ])->assertStatus(201);

        // تأكيد الاستلام — للطالب والمعلم (ليس بصيغة المراجع)
        foreach ([$studentUser, $teacher->user] as $party) {
            Notification::assertSentTo(
                $party,
                RescheduleRequestSubmitted::class,
                fn ($n) => $n->toArray($party)['forReviewer'] === false
                    && $n->toArray($party)['proposedScheduledAt'] === $proposedAt->toIso8601String(),
            );
        }

        // تنبيه المراجعة — لكل أدمن نشط، لا للأدمن المعطَّل
        foreach ([$admin1, $admin2] as $admin) {
            Notification::assertSentTo(
                $admin,
                RescheduleRequestSubmitted::class,
                fn ($n) => $n->toArray($admin)['forReviewer'] === true,
            );
        }
        Notification::assertNotSentTo($inactiveAdmin, RescheduleRequestSubmitted::class);
    }

    public function test_cannot_request_reschedule_for_an_ended_session_and_no_request_is_created(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->subHour());

        $response = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDay()->toDateTimeString(),
            'reason' => 'محاولة بعد انتهاء الجلسة',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'لا يمكن طلب تغيير موعد جلسة بدأت بالفعل أو انتهت');

        $this->assertDatabaseMissing('reschedule_requests', [
            'class_session_id' => $session->id,
            'requested_by' => $teacher->user_id,
        ]);
    }

    public function test_cannot_request_reschedule_for_a_session_that_is_currently_in_progress(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        // بدأت قبل 10 دقائق ومدتها 60 → تعمل الآن
        $session = $this->createSession($teacher, now()->subMinutes(10));

        $response = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDay()->toDateTimeString(),
            'reason' => 'محاولة أثناء عمل الجلسة',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'لا يمكن طلب تغيير موعد جلسة بدأت بالفعل أو انتهت');
        $this->assertDatabaseMissing('reschedule_requests', ['class_session_id' => $session->id]);
    }

    public function test_cannot_request_reschedule_for_an_unpaid_session_and_no_request_is_created(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5), bookingStatus: 'pending_payment');

        $response = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'محاولة قبل اكتمال الدفع',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'لا يمكن تغيير موعد جلسة غير مدفوعة.');

        $this->assertDatabaseMissing('reschedule_requests', ['class_session_id' => $session->id]);
        $this->assertSame('scheduled', $session->fresh()->status);
        $this->assertSame(
            $session->scheduled_at->toDateTimeString(),
            $session->fresh()->scheduled_at->toDateTimeString(),
        );
    }

    /**
     * حجز وافق عليه المعلم (أُنشئت جلساته) لكن انقضت مهلة الدفع المؤقتة دون
     * إتمامه → status='expired'. لا معنى لتغيير موعد جلسة اشتراكها لم يعد
     * قائماً أصلاً، بصرف النظر عن status الجلسة نفسها (لا يزال scheduled).
     */
    public function test_cannot_request_reschedule_for_a_session_whose_booking_expired(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5), bookingStatus: 'expired');

        $response = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'محاولة على حجز منتهي الصلاحية',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'لا يمكن تغيير موعد جلسة اشتراكها لم يعد قائماً.');

        $this->assertDatabaseMissing('reschedule_requests', ['class_session_id' => $session->id]);
    }

    public function test_cannot_request_reschedule_for_a_session_whose_booking_was_cancelled(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5), bookingStatus: 'cancelled');

        $response = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'محاولة على حجز ملغى',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'لا يمكن تغيير موعد جلسة اشتراكها لم يعد قائماً.');

        $this->assertDatabaseMissing('reschedule_requests', ['class_session_id' => $session->id]);
    }

    /**
     * موعد جلسة الباقة الجماعية مشترك بين عدة طلاب دفعوا عليه معاً — تغييره
     * لأجل طرف واحد يكسر جدول البقية بلا أي تنسيق بينهم، فطلبات تغيير الموعد
     * تبقى متاحة فقط لجلسات الباقات الفردية.
     */
    public function test_cannot_request_reschedule_for_a_group_package_session_and_no_request_is_created(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5), sessionFormat: 'group');

        $response = $this->as($teacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'محاولة على جلسة باقة جماعية',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'طلبات تغيير الموعد متاحة فقط لجلسات الباقات الفردية');

        $this->assertDatabaseMissing('reschedule_requests', ['class_session_id' => $session->id]);
    }

    public function test_a_student_whose_own_booking_is_unpaid_cannot_request_reschedule(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5), bookingStatus: 'pending_payment');

        $studentUser = User::factory()->student()->create();
        $student = Student::create(['user_id' => $studentUser->id, 'education_type' => 'school']);
        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'booking_id' => $session->booking_id,
            'attendance' => 'registered',
        ]);
        $studentToken = $studentUser->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'لا يمكن تغيير موعد جلسة غير مدفوعة.');

        $this->assertDatabaseMissing('reschedule_requests', ['class_session_id' => $session->id]);
    }

    public function test_a_teacher_who_does_not_own_the_session_cannot_request_reschedule(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));

        [, $otherTeacherToken] = $this->createVerifiedTeacher();

        $response = $this->as($otherTeacherToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ]);

        $response->assertStatus(403);
    }

    public function test_a_student_not_attending_the_session_cannot_request_reschedule(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $session = $this->createSession($teacher, now()->addDays(5));

        $otherStudentUser = User::factory()->student()->create();
        Student::create(['user_id' => $otherStudentUser->id, 'education_type' => 'school']);
        $otherStudentToken = $otherStudentUser->createToken('t')->plainTextToken;

        $response = $this->as($otherStudentToken)->postJson("/api/class-sessions/{$session->id}/reschedule-requests", [
            'proposed_scheduled_at' => now()->addDays(6)->toDateTimeString(),
            'reason' => 'سبب',
        ]);

        $response->assertStatus(403);
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $token = $teacherUser->createToken('t')->plainTextToken;

        return [$teacher, $token];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createAdmin(): array
    {
        $admin = User::factory()->admin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }

    private function createSession(Teacher $teacher, Carbon $scheduledAt, string $bookingStatus = 'confirmed', string $sessionFormat = 'individual'): ClassSession
    {
        $subject = Subject::create(['code' => 'rs-'.uniqid(), 'name_ar' => 'مادة']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة',
            'subject_id' => $subject->id,
            'session_format' => $sessionFormat,
            'capacity' => $sessionFormat === 'group' ? 5 : 1,
            'sessions_count' => 4,
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
            'sessions_total' => 4,
            'sessions_remaining' => 4,
            'status' => $bookingStatus,
        ]);

        return $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);
    }
}
