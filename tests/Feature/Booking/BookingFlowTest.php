<?php

namespace Tests\Feature\Booking;

use App\Jobs\CreateBbbRoomJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_booking_request_and_approval_creates_sessions_and_payment(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $teacherUser = User::find($teacher->user_id);
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60, sessionsCount: 4);
        $student = $this->createStudent();

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $requested = app(BookingService::class)->requestIndividualBooking(
            $student, $package, $this->weeklySlots($nextWednesday, 4),
        );

        // الطلب لا يُنشئ جلسات ولا دفعاً — بانتظار موافقة المعلم فقط
        $this->assertSame('pending_teacher_confirmation', $requested->status);
        $this->assertSame(0, DB::table('class_sessions')->where('booking_id', $requested->id)->count());
        $this->assertSame(0, DB::table('payments')->where('booking_id', $requested->id)->count());

        $booking = app(BookingService::class)->approveIndividualRequest($requested, $teacherUser);

        // teacher_price=100 سعر الساعة/الجلسة الواحدة × 4 جلسات = 400 قبل الهامش، ثم ×1.6 = 640 للطالب
        $this->assertSame('pending_payment', $booking->status);
        $this->assertEquals(640.0, (float) $booking->amount_paid);
        $this->assertEquals(400.0, (float) $booking->teacher_amount);
        $this->assertEquals(240.0, (float) $booking->platform_amount);
        $this->assertEquals(60.0, (float) $booking->margin_percent_snapshot);
        $this->assertSame(4, $booking->sessions_total);
        $this->assertSame(0, $booking->sessions_used);
        $this->assertSame(4, $booking->sessions_remaining);

        // صحة CHECK constraint (chk_bookings_amounts) بالحساب المباشر
        $this->assertEqualsWithDelta(
            (float) $booking->teacher_amount + (float) $booking->platform_amount,
            (float) $booking->amount_paid,
            0.001,
        );

        // الفردي الآن يُنشئ كل جلسات sessions_count دفعة واحدة، كالجماعي تماماً
        $this->assertCount(4, $booking->sessions);
        $this->assertSame(1, $booking->sessions->first()->sequence_no);
        // مدة الجلسة = إعداد session_duration_minutes الثابت (60 افتراضياً)
        $this->assertSame(60, $booking->sessions->first()->duration_min);
        $this->assertDatabaseHas('session_attendees', [
            'class_session_id' => $booking->sessions->first()->id,
            'student_id' => $student->id,
            'booking_id' => $booking->id,
        ]);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'status' => 'pending',
            'method' => 'stripe',
            'amount' => 640,
        ]);

        // الباقة لم تُحجز نهائياً بعد (لم يُؤكَّد الدفع) — لا زيادة على enrolled_count
        $this->assertSame(0, $package->fresh()->enrolled_count);
    }

    public function test_booking_request_rejected_when_date_not_among_packages_available_days(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();

        // لا جدول محدد لهذه الباقة أصلاً → لا يوجد يوم يطابقه أي تاريخ
        try {
            app(BookingService::class)->requestIndividualBooking($student, $package, $this->weeklySlots(Carbon::now()->addDay(), 4));
            $this->fail('يفترض أن تفشل العملية بسبب عدم وجود جدول للباقة');
        } catch (ValidationException) {
            // متوقع
        }

        $this->assertSame(0, DB::table('bookings')->count());
        $this->assertSame(0, DB::table('class_sessions')->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_confirm_booking_transitions_status_increments_enrolled_count_and_dispatches_bbb_job(): void
    {
        Queue::fake();

        [$teacher] = $this->createVerifiedTeacher();
        $teacherUser = User::find($teacher->user_id);
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $requested = app(BookingService::class)->requestIndividualBooking(
            $student, $package, $this->weeklySlots($nextWednesday, 4),
        );
        $booking = app(BookingService::class)->approveIndividualRequest($requested, $teacherUser);

        $confirmed = app(BookingService::class)->confirmBooking($booking);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertNull($confirmed->hold_expires_at);

        // النصاب الفردي = 1 → الباقة تصبح full تلقائياً بعد أول حجز مؤكَّد
        $this->assertSame(1, $package->fresh()->enrolled_count);
        $this->assertSame('full', $package->fresh()->status);

        Queue::assertPushed(CreateBbbRoomJob::class);

        // عملية idempotent: استدعاء ثانٍ لا يزيد enrolled_count مرة أخرى
        app(BookingService::class)->confirmBooking($confirmed->fresh());
        $this->assertSame(1, $package->fresh()->enrolled_count);
    }

    public function test_group_booking_shares_sessions_across_students_and_fills_at_capacity(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveGroupPackage($teacher, capacity: 2, sessionsCount: 3, teacherPrice: 50, margin: 40);

        // ثلاثة تواريخ فعلية بلا تكرار تلقائي — يجب أن تساوي sessions_count بالضبط
        $nextTuesday = Carbon::now()->next(2);
        $nextThursday = Carbon::now()->next(4);
        $tuesdayAfter = $nextTuesday->copy()->addWeek();
        $package->schedules()->create(['date' => $nextTuesday->toDateString(), 'day_of_week' => $nextTuesday->dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);
        $package->schedules()->create(['date' => $nextThursday->toDateString(), 'day_of_week' => $nextThursday->dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);
        $package->schedules()->create(['date' => $tuesdayAfter->toDateString(), 'day_of_week' => $tuesdayAfter->dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);

        $student1 = $this->createStudent();
        $student2 = $this->createStudent();

        // أول حجز هو "مالك" الجلسات فعلياً (class_sessions.booking_id)
        $booking1 = app(BookingService::class)->joinGroupPackage($student1, $package);
        $this->assertCount(3, $booking1->sessions);
        // مدة كل جلسة = إعداد session_duration_minutes الثابت (60 افتراضياً)
        $this->assertSame(60, $booking1->sessions->first()->duration_min);

        // الانضمام الثاني لا يملك أي جلسة مباشرة (booking_id يبقى لصاحب أول حجز) —
        // لكنه يحضرها فعلياً عبر session_attendees
        $booking2 = app(BookingService::class)->joinGroupPackage($student2, $package);
        $this->assertCount(0, $booking2->sessions);
        $this->assertCount(3, $booking2->attendedSessions);

        // كلا الحجزين يحضران نفس الجلسات بالضبط، وليست جلسات جديدة
        $this->assertSame(
            $booking1->sessions->pluck('id')->sort()->values()->all(),
            $booking2->attendedSessions->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame(3, ClassSession::count());

        app(BookingService::class)->confirmBooking($booking1);
        app(BookingService::class)->confirmBooking($booking2);

        $this->assertSame(2, $package->fresh()->enrolled_count);
        $this->assertSame('full', $package->fresh()->status);

        foreach ($booking1->sessions as $session) {
            $this->assertSame(2, $session->attendees()->count());
        }
    }

    public function test_joining_a_group_package_whose_schedule_dates_all_passed_is_rejected_as_unavailable(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveGroupPackage($teacher, capacity: 5, sessionsCount: 2, teacherPrice: 50, margin: 40);

        // كل تواريخ الجدول في الماضي — الباقة "منتهية" فعلياً رغم بقاء status='active'
        $package->schedules()->create(['date' => Carbon::now()->subDays(10)->toDateString(), 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);
        $package->schedules()->create(['date' => Carbon::now()->subDays(3)->toDateString(), 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']);

        $this->assertFalse($package->isBookable());

        $student = $this->createStudent();

        try {
            app(BookingService::class)->joinGroupPackage($student, $package);
            $this->fail('توقّعنا ValidationException لأن الباقة منتهية');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // الرسالة الصحيحة — لا رسالة تعارض جدول
            $this->assertSame('هذه الباقة غير متاحة للحجز', $e->errors()['package'][0]);
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_manual_booking_is_confirmed_immediately_and_audit_logged(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();
        $admin = User::factory()->admin()->create();

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $booking = app(BookingService::class)->createManualBooking(
            $student,
            $package,
            $admin,
            'تعويض عن جلسة ملغاة — شكوى #C-1042',
            $this->weeklySlots($nextWednesday, 4),
        );

        $this->assertSame('confirmed', $booking->status);
        $this->assertTrue($booking->is_manual);
        $this->assertSame($admin->id, $booking->created_by_admin_id);
        $this->assertNotNull($booking->manual_reason);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'method' => 'manual',
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booking.manual_created',
            'user_id' => $admin->id,
        ]);

        $this->assertSame(1, $package->fresh()->enrolled_count);
    }

    public function test_manual_booking_endpoint_requires_admin_and_reason(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $forbidden = $this->as($teacherToken)->postJson("/api/packages/{$package->id}/bookings/manual", [
            'student_id' => $student->id,
            'reason' => 'محاولة غير مصرحة',
            'slots' => $this->weeklySlots($nextWednesday, 4),
        ]);
        $forbidden->assertStatus(403);

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $missingReason = $this->as($adminToken)->postJson("/api/packages/{$package->id}/bookings/manual", [
            'student_id' => $student->id,
            'slots' => $this->weeklySlots($nextWednesday, 4),
        ]);
        $missingReason->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $ok = $this->as($adminToken)->postJson("/api/packages/{$package->id}/bookings/manual", [
            'student_id' => $student->id,
            'reason' => 'حجز يدوي صحيح',
            'slots' => $this->weeklySlots($nextWednesday, 4),
        ]);
        $ok->assertStatus(201)->assertJsonPath('data.status', 'confirmed');
    }

    public function test_individual_booking_request_http_flow_ends_with_checkout_url(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $teacherUser = User::find($teacher->user_id);
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        // اليوم غير المتاح يُرفض
        $wrongDay = $this->as($studentToken)->postJson("/api/packages/{$package->id}/bookings/individual", [
            'slots' => $this->weeklySlots($nextWednesday->copy()->addDay(), 4),
        ]);
        $wrongDay->assertStatus(422)->assertJsonValidationErrors(['requested_date']);

        $request = $this->as($studentToken)->postJson("/api/packages/{$package->id}/bookings/individual", [
            'slots' => $this->weeklySlots($nextWednesday, 4),
        ]);
        $request->assertStatus(201)->assertJsonPath('data.status', 'pending_teacher_confirmation');
        $bookingId = $request->json('data.id');

        // طالب آخر (غير صاحب الطلب) لا يملك صلاحية الموافقة
        $otherStudentToken = User::find($this->createStudent()->user_id)->createToken('t')->plainTextToken;
        $forbidden = $this->as($otherStudentToken)->postJson("/api/bookings/{$bookingId}/approve");
        $forbidden->assertStatus(403);

        $approve = $this->as($teacherToken)->postJson("/api/bookings/{$bookingId}/approve");
        $approve->assertStatus(200)->assertJsonPath('data.status', 'pending_payment');

        $checkout = $this->as($studentToken)->postJson("/api/bookings/{$bookingId}/checkout");
        $checkout->assertStatus(200);
        $this->assertNotEmpty($checkout->json('data.checkout_url'));

        $this->assertSame(4, DB::table('class_sessions')->where('booking_id', $bookingId)->count());
    }

    public function test_teacher_can_reject_individual_booking_request_with_reason(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $request = $this->as($studentToken)->postJson("/api/packages/{$package->id}/bookings/individual", [
            'slots' => $this->weeklySlots($nextWednesday, 4),
        ]);
        $bookingId = $request->json('data.id');

        $reject = $this->as($teacherToken)->postJson("/api/bookings/{$bookingId}/reject", [
            'reason' => 'الوقت غير مناسب',
        ]);
        $reject->assertStatus(200)->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, DB::table('class_sessions')->where('booking_id', $bookingId)->count());
        $this->assertSame(0, DB::table('payments')->where('booking_id', $bookingId)->count());

        // لا يمكن الموافقة على طلب مرفوض بالفعل
        $approveAfterReject = $this->as($teacherToken)->postJson("/api/bookings/{$bookingId}/approve");
        $approveAfterReject->assertStatus(422);
    }

    public function test_teacher_cannot_approve_booking_request_after_requested_time_has_passed(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();
        $studentToken = User::find($student->user_id)->createToken('t')->plainTextToken;

        $today = Carbon::today();
        $package->schedules()->create(['day_of_week' => $today->dayOfWeek]);

        $request = $this->as($studentToken)->postJson("/api/packages/{$package->id}/bookings/individual", [
            // أقرب موعد (اليوم) هو الماضي بالفعل — لحظة انتهاء الطلب تُحسَب من
            // أقرب جلسة، لا من كل الجلسات، فهذا وحده كافٍ لإبطال الطلب كاملاً
            'slots' => $this->weeklySlots($today, 4, now()->subHour()->format('H:i')),
        ]);
        $request->assertStatus(201);

        $bookingId = $request->json('data.id');

        $approve = $this->as($teacherToken)->postJson("/api/bookings/{$bookingId}/approve");

        $approve->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'انتهى وقت الجلسة المقترحة دون موافقة المعلم.');

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'status' => 'expired',
            'cancellation_reason' => 'انتهى وقت الجلسة المقترحة دون موافقة المعلم.',
        ]);
        $this->assertSame(0, DB::table('class_sessions')->where('booking_id', $bookingId)->count());
        $this->assertSame(0, DB::table('payments')->where('booking_id', $bookingId)->count());
    }

    public function test_teacher_can_filter_bookings_by_status_to_see_pending_requests(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();

        $nextWednesday = Carbon::now()->next(3);
        $package->schedules()->create(['day_of_week' => $nextWednesday->dayOfWeek]);

        $requested = app(BookingService::class)->requestIndividualBooking(
            $student, $package, $this->weeklySlots($nextWednesday, 4),
        );

        $response = $this->as($teacherToken)->getJson('/api/bookings?status=pending_teacher_confirmation');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$requested->id], $ids->all());
        $this->assertSame($student->id, $response->json('data.0.student.id'));
        $this->assertNotNull($response->json('data.0.student.user.name'));
    }

    public function test_database_check_constraint_rejects_inconsistent_booking_amounts(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $student = $this->createStudent();

        $this->expectException(QueryException::class);

        DB::table('bookings')->insert([
            'reference' => 'BK-BADCHECK1',
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 50, // 100 + 50 != 160 → ينتهك chk_bookings_amounts
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'sessions_remaining' => 4,
            'status' => 'pending_payment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_teacher_sees_only_their_own_bookings_via_index_endpoint(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        [$otherTeacher] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $otherPackage = $this->createActiveIndividualPackage($otherTeacher, teacherPrice: 80, margin: 50);
        $student = $this->createStudent();

        $myBooking = Booking::create([
            'reference' => 'BK-MINE-'.rand(1000, 9999),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160,
            'teacher_amount' => 100,
            'platform_amount' => 60,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'sessions_remaining' => 4,
            'status' => 'confirmed',
        ]);

        $otherBooking = Booking::create([
            'reference' => 'BK-OTH-'.rand(1000, 9999),
            'student_id' => $student->id,
            'teacher_id' => $otherTeacher->id,
            'package_id' => $otherPackage->id,
            'amount_paid' => 120,
            'teacher_amount' => 80,
            'platform_amount' => 40,
            'margin_percent_snapshot' => 50,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'sessions_remaining' => 4,
            'status' => 'confirmed',
        ]);

        $response = $this->as($teacherToken)->getJson('/api/bookings');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($myBooking->id));
        $this->assertFalse($ids->contains($otherBooking->id));
    }

    public function test_teacher_can_filter_own_bookings_by_student_id(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $package = $this->createActiveIndividualPackage($teacher, teacherPrice: 100, margin: 60);
        $studentA = $this->createStudent();
        $studentB = $this->createStudent();

        $bookingA = Booking::create([
            'reference' => 'BK-A-'.rand(1000, 9999),
            'student_id' => $studentA->id,
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

        Booking::create([
            'reference' => 'BK-B-'.rand(1000, 9999),
            'student_id' => $studentB->id,
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

        $response = $this->as($teacherToken)->getJson("/api/bookings?student_id={$studentA->id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$bookingA->id], $ids->all());
    }

    /** موعد مستقل لكل جلسة، أسبوعياً بدءاً من $anchor — نفس التكرار الذي كان تلقائياً سابقاً، مبنياً الآن صراحةً كمصفوفة slots. */
    private function weeklySlots(Carbon $anchor, int $count, string $time = '10:00'): array
    {
        return collect(range(0, $count - 1))
            ->map(fn (int $i) => ['date' => $anchor->copy()->addWeeks($i)->toDateString(), 'start_time' => $time])
            ->all();
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

    private function createStudent(): Student
    {
        $user = User::factory()->student()->create();

        return Student::create(['user_id' => $user->id, 'education_type' => 'school']);
    }

    private function createActiveIndividualPackage(Teacher $teacher, float $teacherPrice, float $margin, int $sessionsCount = 4): Package
    {
        $subject = Subject::create(['code' => 'subj-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);
        $computed = app(PricingService::class)->calculateStudentPrice($teacherPrice, $margin, $sessionsCount);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة فردية',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => $sessionsCount,
            'teacher_price' => $teacherPrice,
            'platform_margin_percent' => $margin,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
        ]);
    }

    private function createActiveGroupPackage(Teacher $teacher, int $capacity, int $sessionsCount, float $teacherPrice, float $margin): Package
    {
        $subject = Subject::create(['code' => 'subj-'.uniqid(), 'name_ar' => 'مادة مجموعة', 'education_type' => 'school']);
        $computed = app(PricingService::class)->calculateStudentPrice($teacherPrice, $margin, $sessionsCount);

        return Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة مجموعة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => $capacity,
            'sessions_count' => $sessionsCount,
            'teacher_price' => $teacherPrice,
            'platform_margin_percent' => $margin,
            'student_price' => $computed['student_price'],
            'platform_revenue' => $computed['platform_revenue'],
            'status' => 'active',
            'approved_at' => now(),
        ]);
    }
}
