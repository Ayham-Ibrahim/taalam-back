<?php

namespace Tests\Feature\Session;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseField;
use App\Models\Package;
use App\Models\RescheduleRequest;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClassSessionListTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_sees_only_their_own_sessions_via_index(): void
    {
        [$teacherA, $tokenA] = $this->createVerifiedTeacher();
        [$teacherB] = $this->createVerifiedTeacher();

        [, $sessionA] = $this->createBookingWithSession($teacherA, now()->addDay());
        [, $sessionB] = $this->createBookingWithSession($teacherB, now()->addDay());

        $response = $this->as($tokenA)->getJson('/api/class-sessions');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sessionA->id));
        $this->assertFalse($ids->contains($sessionB->id));
    }

    public function test_student_sees_only_sessions_they_attend(): void
    {
        [$teacher] = $this->createVerifiedTeacher();

        [$booking1, $session1] = $this->createBookingWithSession($teacher, now()->addDay());
        $student1User = User::find($booking1->student->user_id);
        $student1Token = $student1User->createToken('t')->plainTextToken;
        $this->attendeeFor($session1, $booking1);

        [$booking2, $session2] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $this->attendeeFor($session2, $booking2);

        $response = $this->as($student1Token)->getJson('/api/class-sessions');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($session1->id));
        $this->assertFalse($ids->contains($session2->id));
    }

    public function test_teacher_does_not_see_a_session_whose_every_attendee_booking_is_expired(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();

        // جلسة فردية انتهت صلاحية حجزها الوحيد → لن تُعقد → تختفي من جدول المعلم
        [$deadBooking, $deadSession] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $this->attendeeFor($deadSession, $deadBooking);
        $deadBooking->update(['status' => 'expired']);

        // جلسة بحجز قائم → تبقى ظاهرة
        [$liveBooking, $liveSession] = $this->createBookingWithSession($teacher, now()->addDays(3));
        $this->attendeeFor($liveSession, $liveBooking);

        $response = $this->as($token)->getJson('/api/class-sessions');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($deadSession->id));
        $this->assertTrue($ids->contains($liveSession->id));
    }

    public function test_teacher_still_sees_a_group_session_when_at_least_one_attendee_booking_is_live(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();

        [$expiredBooking, $session] = $this->createBookingWithSession($teacher, now()->addDays(2), 'group');
        $this->attendeeFor($session, $expiredBooking);
        $expiredBooking->update(['status' => 'expired']);

        // طالب ثانٍ على نفس جلسة المجموعة ما زال حجزه قائماً
        $otherStudentUser = User::factory()->student()->create();
        $otherStudent = Student::create(['user_id' => $otherStudentUser->id, 'education_type' => 'school']);
        $liveBooking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'package_id' => $expiredBooking->package_id,
            'amount_paid' => 160, 'teacher_amount' => 100, 'platform_amount' => 60,
            'margin_percent_snapshot' => 60, 'sessions_total' => 4, 'sessions_remaining' => 4,
            'status' => 'confirmed',
        ]);
        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $otherStudent->id,
            'booking_id' => $liveBooking->id,
            'attendance' => 'registered',
        ]);

        $response = $this->as($token)->getJson('/api/class-sessions');

        $response->assertStatus(200);
        $this->assertTrue(collect($response->json('data'))->pluck('id')->contains($session->id));
    }

    public function test_student_does_not_see_sessions_whose_booking_expired_or_was_cancelled(): void
    {
        [$teacher] = $this->createVerifiedTeacher();

        // الطالب صاحب الحجز المنتهي — لا يجب أن يرى جلسته إطلاقاً في قائمته
        [$expiredBooking, $expiredSession] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $this->attendeeFor($expiredSession, $expiredBooking);
        $expiredBooking->update(['status' => 'expired']);

        $expiredStudentUser = User::find($expiredBooking->student->user_id);
        $expiredStudentToken = $expiredStudentUser->createToken('t')->plainTextToken;

        $response = $this->as($expiredStudentToken)->getJson('/api/class-sessions');
        $response->assertStatus(200);
        $this->assertFalse(collect($response->json('data'))->pluck('id')->contains($expiredSession->id));

        // الطالب صاحب الحجز الملغى — نفس السلوك
        [$cancelledBooking, $cancelledSession] = $this->createBookingWithSession($teacher, now()->addDays(3));
        $this->attendeeFor($cancelledSession, $cancelledBooking);
        $cancelledBooking->update(['status' => 'cancelled']);

        $cancelledStudentUser = User::find($cancelledBooking->student->user_id);
        $cancelledStudentToken = $cancelledStudentUser->createToken('t')->plainTextToken;

        $response = $this->as($cancelledStudentToken)->getJson('/api/class-sessions');
        $response->assertStatus(200);
        $this->assertFalse(collect($response->json('data'))->pluck('id')->contains($cancelledSession->id));

        // ضبط: جلسة بحجز صالح تبقى ظاهرة
        [$validBooking, $validSession] = $this->createBookingWithSession($teacher, now()->addDay());
        $this->attendeeFor($validSession, $validBooking);
        $validStudentUser = User::find($validBooking->student->user_id);
        $validStudentToken = $validStudentUser->createToken('t')->plainTextToken;

        $response = $this->as($validStudentToken)->getJson('/api/class-sessions');
        $response->assertStatus(200);
        $this->assertTrue(collect($response->json('data'))->pluck('id')->contains($validSession->id));
    }

    /**
     * السيناريو الواقعي: الحجز ما زال pending_payment (المجدول الدوري لم يلحق به بعد)
     * لكن مهلته المؤقتة انقضت — يجب أن تختفي جلسته فوراً من قائمة الطالب دون انتظار
     * ExpireStaleBookingsJob، عبر التحويل الكسول في ClassSessionController::index.
     */
    public function test_student_does_not_see_sessions_of_a_pending_payment_booking_whose_hold_has_lapsed(): void
    {
        [$teacher] = $this->createVerifiedTeacher();

        [$staleBooking, $staleSession] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $this->attendeeFor($staleSession, $staleBooking);
        $staleBooking->update(['status' => 'pending_payment', 'hold_expires_at' => now()->subMinutes(5)]);

        [$freshBooking, $freshSession] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $this->attendeeFor($freshSession, $freshBooking);
        $freshBooking->update(['status' => 'pending_payment', 'hold_expires_at' => now()->addMinutes(10)]);
        $freshBooking->update(['student_id' => $staleBooking->student_id]);
        $freshSession->attendees()->update(['student_id' => $staleBooking->student_id]);

        $studentUser = User::find($staleBooking->student->user_id);
        $studentToken = $studentUser->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson('/api/class-sessions');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($staleSession->id));
        $this->assertTrue($ids->contains($freshSession->id));
        $this->assertSame('expired', $staleBooking->fresh()->status);
        $this->assertSame('pending_payment', $freshBooking->fresh()->status);
    }

    public function test_admin_sees_sessions_across_all_teachers(): void
    {
        [$teacherA] = $this->createVerifiedTeacher();
        [$teacherB] = $this->createVerifiedTeacher();
        [, $sessionA] = $this->createBookingWithSession($teacherA, now()->addDay());
        [, $sessionB] = $this->createBookingWithSession($teacherB, now()->addDay());

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $response = $this->as($adminToken)->getJson('/api/class-sessions');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sessionA->id));
        $this->assertTrue($ids->contains($sessionB->id));
    }

    public function test_status_filter_is_applied(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();
        [, $scheduled] = $this->createBookingWithSession($teacher, now()->addDay());
        [, $completed] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $completed->update(['status' => 'completed']);

        $response = $this->as($token)->getJson('/api/class-sessions?status=completed');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($completed->id));
        $this->assertFalse($ids->contains($scheduled->id));
    }

    /**
     * الفرونت إند لا يرسل أبداً القيم الخام لعمود status (scheduled/completed/
     * active/no_show_student...) في عناصر تصفية "الحالة" التي يعرضها للمستخدم —
     * يرسل حرفياً upcoming/attended/cancelled (تجميع mapSessionStatus() في
     * services/index.js). كان where('status', 'upcoming') لا يطابق أي سطر أبداً
     * فيرجع صفر نتائج دائماً بصمت — هذا الاختبار يثبت أن كل حالة مجمَّعة تُترجم
     * الآن لمجموعة القيم الخام الصحيحة المطابقة لها.
     */
    public function test_status_filter_translates_the_frontends_grouped_status_values_to_the_matching_raw_statuses(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();

        [, $scheduled] = $this->createBookingWithSession($teacher, now()->addDay());
        [, $active] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $active->update(['status' => 'active']);
        [, $completed] = $this->createBookingWithSession($teacher, now()->addDays(3));
        $completed->update(['status' => 'completed']);
        [, $cancelled] = $this->createBookingWithSession($teacher, now()->addDays(4));
        $cancelled->update(['status' => 'cancelled']);
        [, $noShow] = $this->createBookingWithSession($teacher, now()->addDays(5));
        $noShow->update(['status' => 'no_show_student']);

        $upcoming = $this->as($token)->getJson('/api/class-sessions?status=upcoming');
        $upcomingIds = collect($upcoming->json('data'))->pluck('id');
        $this->assertTrue($upcomingIds->contains($scheduled->id));
        $this->assertTrue($upcomingIds->contains($active->id));
        $this->assertFalse($upcomingIds->contains($completed->id));
        $this->assertFalse($upcomingIds->contains($cancelled->id));

        $attended = $this->as($token)->getJson('/api/class-sessions?status=attended');
        $attendedIds = collect($attended->json('data'))->pluck('id');
        $this->assertEquals([$completed->id], $attendedIds->all());

        $cancelledFilter = $this->as($token)->getJson('/api/class-sessions?status=cancelled');
        $cancelledIds = collect($cancelledFilter->json('data'))->pluck('id');
        $this->assertTrue($cancelledIds->contains($cancelled->id));
        $this->assertTrue($cancelledIds->contains($noShow->id));
        $this->assertFalse($cancelledIds->contains($scheduled->id));
    }

    /**
     * reschedule_pending ليست قيمة تُخزَّن فعلياً في class_sessions.status أبداً
     * (لا مكان في الكود يضبطها) — هي حالة مُشتقّة بالكامل من وجود RescheduleRequest
     * بحالة pending مرتبط بالجلسة. where('status','reschedule_pending') الحرفي
     * كان يرجع صفر نتائج دائماً بصمت لهذا السبب بالضبط، مطابقاً لنفس عطل
     * upcoming/attended الأصلي.
     */
    public function test_status_filter_reschedule_pending_matches_sessions_with_a_pending_reschedule_request(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();

        [$bookingWithRequest, $sessionWithRequest] = $this->createBookingWithSession($teacher, now()->addDay());
        $this->attendeeFor($sessionWithRequest, $bookingWithRequest);
        RescheduleRequest::create([
            'class_session_id' => $sessionWithRequest->id,
            'booking_id' => $bookingWithRequest->id,
            'requested_by' => $teacher->user_id,
            'requester_role' => 'teacher',
            'current_scheduled_at' => $sessionWithRequest->scheduled_at,
            'proposed_scheduled_at' => now()->addDays(2),
            'within_free_window' => false,
            'hours_before_session' => 24,
            'reason' => 'ظرف طارئ',
            'status' => 'pending',
            'sla_due_at' => now()->addHours(12),
        ]);

        [, $plainSession] = $this->createBookingWithSession($teacher, now()->addDays(2));

        $response = $this->as($token)->getJson('/api/class-sessions?status=reschedule_pending');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$sessionWithRequest->id], $ids->all());
        $this->assertFalse($ids->contains($plainSession->id));
    }

    /**
     * الفرونت إند يعرض تبويب فئة مبسّط (all/individual/group/training) يطابق
     * sessionCategory() في dashboard services/index.js — لا يوجد عمود category
     * خام في class_sessions أصلاً. كانت هذه الفئة تُصفّى بالكامل على الفرونت
     * إند بعد ترقيم صفحة من السيرفر بالفعل (كل الفئات مجتمعة)، فتظهر صفحة فارغة
     * تماماً من الفئة المختارة مع عدّاد/ترقيم لإجمالي كل الفئات — هذا الاختبار
     * يثبت أن الفلترة أصبحت فعلية على السيرفر لكل فئة على حدة.
     */
    public function test_category_filter_matches_individual_group_and_training_sessions_separately(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        // إنشاء دورة (course) يتطلب معلماً من نوع training_center تحديداً (قيد
        // على مستوى القاعدة) — بخلاف الحجز/الباقة اللذين لا يفرضان نوعاً بعينه.
        [$center] = $this->createVerifiedTeacher('training_center');
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        [, $individual] = $this->createBookingWithSession($teacher, now()->addDay(), 'individual');
        [, $group] = $this->createBookingWithSession($teacher, now()->addDays(2), 'group');
        $training = $this->createTrainingSession($center, now()->addDays(3));

        $individualResponse = $this->as($adminToken)->getJson('/api/class-sessions?category=individual');
        $individualIds = collect($individualResponse->json('data'))->pluck('id');
        $this->assertEquals([$individual->id], $individualIds->all());

        $groupResponse = $this->as($adminToken)->getJson('/api/class-sessions?category=group');
        $groupIds = collect($groupResponse->json('data'))->pluck('id');
        $this->assertEquals([$group->id], $groupIds->all());

        $trainingResponse = $this->as($adminToken)->getJson('/api/class-sessions?category=training');
        $trainingIds = collect($trainingResponse->json('data'))->pluck('id');
        $this->assertEquals([$training->id], $trainingIds->all());

        $allResponse = $this->as($adminToken)->getJson('/api/class-sessions?category=all');
        $allIds = collect($allResponse->json('data'))->pluck('id');
        $this->assertTrue($allIds->contains($individual->id));
        $this->assertTrue($allIds->contains($group->id));
        $this->assertTrue($allIds->contains($training->id));
    }

    public function test_index_orders_sessions_from_newest_to_oldest_and_paginates(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();

        [, $oldest] = $this->createBookingWithSession($teacher, now()->addDay());
        [, $middle] = $this->createBookingWithSession($teacher, now()->addDays(2));
        [, $newest] = $this->createBookingWithSession($teacher, now()->addDays(3));

        $response = $this->as($token)->getJson('/api/class-sessions?per_page=2&page=1');

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $middle->id);

        $pageTwo = $this->as($token)->getJson('/api/class-sessions?per_page=2&page=2');
        $pageTwo->assertStatus(200)
            ->assertJsonPath('data.0.id', $oldest->id);
    }

    public function test_sort_asc_orders_sessions_from_nearest_to_farthest(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();

        [, $nearest] = $this->createBookingWithSession($teacher, now()->addDay());
        [, $middle] = $this->createBookingWithSession($teacher, now()->addDays(2));
        [, $farthest] = $this->createBookingWithSession($teacher, now()->addDays(3));

        $response = $this->as($token)->getJson('/api/class-sessions?sort=asc');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $nearest->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $farthest->id);
    }

    public function test_teacher_can_filter_sessions_by_student_id(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();

        [$bookingA, $sessionA] = $this->createBookingWithSession($teacher, now()->addDay());
        $this->attendeeFor($sessionA, $bookingA);

        [$bookingB, $sessionB] = $this->createBookingWithSession($teacher, now()->addDays(2));
        $this->attendeeFor($sessionB, $bookingB);

        $response = $this->as($token)->getJson("/api/class-sessions?student_id={$bookingA->student_id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$sessionA->id], $ids->all());
    }

    public function test_show_endpoint_rejects_student_not_attending_the_session(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$booking, $session] = $this->createBookingWithSession($teacher, now()->addDay());
        $this->attendeeFor($session, $booking);

        $outsider = User::factory()->student()->create();
        $outsiderToken = $outsider->createToken('t')->plainTextToken;

        $response = $this->as($outsiderToken)->getJson("/api/class-sessions/{$session->id}");

        $response->assertStatus(403);
    }

    public function test_show_endpoint_hides_teacher_join_url_from_student(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [$booking, $session] = $this->createBookingWithSession($teacher, now()->addDay());
        $session->update(['join_url_teacher' => 'https://bbb.example.com/join/teacher', 'join_url_student' => 'https://bbb.example.com/join/student']);
        $this->attendeeFor($session, $booking);

        $studentUser = User::find($booking->student->user_id);
        $studentToken = $studentUser->createToken('t')->plainTextToken;

        $response = $this->as($studentToken)->getJson("/api/class-sessions/{$session->id}");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.join_url_teacher'));
        $this->assertNotNull($response->json('data.join_url_student'));
    }

    public function test_index_exposes_pending_reschedule_request_flag(): void
    {
        [$teacher, $token] = $this->createVerifiedTeacher();
        [$booking, $session] = $this->createBookingWithSession($teacher, now()->addDay());
        $this->attendeeFor($session, $booking);

        RescheduleRequest::create([
            'class_session_id' => $session->id,
            'booking_id' => $booking->id,
            'requested_by' => $teacher->user_id,
            'requester_role' => 'teacher',
            'current_scheduled_at' => $session->scheduled_at,
            'proposed_scheduled_at' => now()->addDays(2),
            'within_free_window' => false,
            'hours_before_session' => 24,
            'reason' => 'ظرف طارئ',
            'status' => 'pending',
            'sla_due_at' => now()->addHours(12),
        ]);

        $response = $this->as($token)->getJson('/api/class-sessions');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.has_pending_reschedule_request', true)
            ->assertJsonPath('data.0.pending_reschedule_request_status', 'pending');
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(string $teacherType = 'school'): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => $teacherType, 'status' => 'verified']);
        $token = $teacherUser->createToken('t')->plainTextToken;

        return [$teacher, $token];
    }

    /**
     * @return array{0: Booking, 1: ClassSession}
     */
    private function createBookingWithSession(Teacher $teacher, Carbon $scheduledAt, string $sessionFormat = 'individual'): array
    {
        $subject = Subject::create(['code' => 'cs-'.uniqid(), 'name_ar' => 'مادة']);

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
            'status' => 'confirmed',
        ]);

        $session = $booking->sessions()->create([
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);

        return [$booking, $session];
    }

    private function attendeeFor(ClassSession $session, Booking $booking): SessionAttendee
    {
        return SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);
    }

    private function createTrainingSession(Teacher $teacher, Carbon $scheduledAt): ClassSession
    {
        $field = CourseField::create(['code' => 'field-'.uniqid(), 'name_ar' => 'مجال']);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title' => 'دورة',
            'course_field_id' => $field->id,
            'start_date' => $scheduledAt->toDateString(),
            'end_date' => $scheduledAt->copy()->addDays(6)->toDateString(),
            'total_sessions' => 1,
            'max_seats' => 20,
            'provider_price' => 100,
            'platform_margin_percent' => 50,
            'student_price' => 150,
            'platform_revenue' => 50,
            'status' => 'active',
            'approved_at' => now(),
            'requires_laptop' => false,
            'materials_included' => true,
            'has_practical_exercises' => true,
            'sessions_recorded' => false,
        ]);

        return ClassSession::create([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'sequence_no' => 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => 'scheduled',
        ]);
    }
}
