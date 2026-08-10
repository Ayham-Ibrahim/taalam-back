<?php

namespace App\Services;

use App\Jobs\CreateBbbRoomJob;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    use LogsAuditEvents;

    public function __construct(private readonly SettingsService $settings) {}

    public function initiateEnrollment(Student $student, Course $course): Enrollment
    {
        $this->assertCourseEnrollable($course);
        $this->assertNotAlreadyEnrolled($student, $course);

        return DB::transaction(function () use ($student, $course) {
            $enrollment = $this->createEnrollmentRecord($student, $course);

            $sessions = $this->courseSessionsFor($course);

            foreach ($sessions as $session) {
                SessionAttendee::create([
                    'class_session_id' => $session->id,
                    'student_id' => $student->id,
                    'enrollment_id' => $enrollment->id,
                    'attendance' => 'registered',
                ]);
            }

            $this->createPendingPayment($enrollment);

            return $enrollment->fresh();
        });
    }

    public function createManualEnrollment(Student $student, Course $course, User $admin, string $reason): Enrollment
    {
        $this->assertCourseEnrollable($course);
        $this->assertNotAlreadyEnrolled($student, $course);

        return DB::transaction(function () use ($student, $course, $admin, $reason) {
            $enrollment = $this->createEnrollmentRecord($student, $course, [
                'is_manual' => true,
                'created_by_admin_id' => $admin->id,
                'manual_reason' => $reason,
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'hold_expires_at' => null,
            ]);

            $sessions = $this->courseSessionsFor($course);

            foreach ($sessions as $session) {
                SessionAttendee::create([
                    'class_session_id' => $session->id,
                    'student_id' => $student->id,
                    'enrollment_id' => $enrollment->id,
                    'attendance' => 'registered',
                ]);
            }

            $this->createManualPayment($enrollment);
            $this->incrementEnrolledCount($course);

            $this->audit('enrollment.manual_created', $enrollment, [], [
                'student_id' => $student->id,
                'course_id' => $course->id,
            ], $reason, $admin->id);

            return $enrollment->fresh();
        });
    }

    public function confirmEnrollment(Enrollment $enrollment): Enrollment
    {
        if ($enrollment->status !== 'pending_payment') {
            return $enrollment;
        }

        $enrollment->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'hold_expires_at' => null,
        ]);

        $enrollment->loadMissing('course.sessions');
        $this->incrementEnrolledCount($enrollment->course);

        foreach ($enrollment->course->sessions as $session) {
            CreateBbbRoomJob::dispatch($session->id);
        }

        return $enrollment;
    }

    /**
     * عمداً update() وليس increment() — increment() ينفّذ UPDATE مباشراً في قاعدة
     * البيانات متجاوزاً حدث saving، فلا يعمل CourseObserver (الإغلاق التلقائي عند full).
     */
    private function incrementEnrolledCount(Course $course): void
    {
        $course->update(['enrolled_count' => $course->enrolled_count + 1]);
    }

    private function assertCourseEnrollable(Course $course): void
    {
        if ($course->status !== 'active') {
            throw ValidationException::withMessages([
                'course' => ['هذه الدورة غير متاحة للتسجيل حالياً'],
            ]);
        }

        if ($course->enrolled_count >= $course->max_seats) {
            throw ValidationException::withMessages([
                'course' => ['اكتملت مقاعد هذه الدورة'],
            ]);
        }
    }

    /**
     * قيد قاعدة البيانات unique(student_id, course_id) صارم بغض النظر عن الحالة —
     * لا يمكن لطالب أن يملك أكثر من سطر تسجيل واحد لنفس الدورة حتى لو أُلغي سابقاً.
     */
    private function assertNotAlreadyEnrolled(Student $student, Course $course): void
    {
        $exists = Enrollment::where('student_id', $student->id)
            ->where('course_id', $course->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'course' => ['الطالب مسجَّل بالفعل في هذه الدورة'],
            ]);
        }
    }

    private function createEnrollmentRecord(Student $student, Course $course, array $overrides = []): Enrollment
    {
        return Enrollment::create(array_merge([
            'reference' => 'EN-'.strtoupper(Str::random(10)),
            'student_id' => $student->id,
            'course_id' => $course->id,
            'teacher_id' => $course->teacher_id,
            'amount_paid' => $course->student_price,
            // hourly: provider_price سعر الساعة — الإجمالي المستحق للمركز = ×total_hours. total: provider_price هو الإجمالي أصلاً
            'provider_amount' => $course->pricing_mode === 'hourly'
                ? round($course->provider_price * $course->total_hours, 2)
                : $course->provider_price,
            'platform_amount' => $course->platform_revenue,
            'margin_percent_snapshot' => $course->platform_margin_percent,
            'currency' => $course->currency,
            'status' => 'pending_payment',
            'hold_expires_at' => now()->addMinutes((int) $this->settings->get('booking_payment_hold_minutes', 15)),
        ], $overrides));
    }

    private function createPendingPayment(Enrollment $enrollment): Payment
    {
        return Payment::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'amount' => $enrollment->amount_paid,
            'provider_amount' => $enrollment->provider_amount,
            'platform_amount' => $enrollment->platform_amount,
            'currency' => $enrollment->currency,
            'method' => 'stripe',
            'status' => 'pending',
        ]);
    }

    private function createManualPayment(Enrollment $enrollment): Payment
    {
        return Payment::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'amount' => $enrollment->amount_paid,
            'provider_amount' => $enrollment->provider_amount,
            'platform_amount' => $enrollment->platform_amount,
            'currency' => $enrollment->currency,
            'method' => 'manual',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    /**
     * تُنشئ جلسات الدورة مرة واحدة فقط (عند أول تسجيل) بدءاً من start_date وحتى
     * end_date وفق أيام/أوقات course_schedules الثابتة — نطاق محدود طبيعياً بتاريخي الدورة.
     */
    private function courseSessionsFor(Course $course): Collection
    {
        $existing = $course->sessions()->orderBy('sequence_no')->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $schedules = $course->schedules()->get();

        if ($schedules->isEmpty()) {
            throw ValidationException::withMessages([
                'course' => ['لا يوجد جدول محدد لهذه الدورة بعد'],
            ]);
        }

        $dates = [];
        $cursor = $course->start_date->copy();

        while ($cursor->lessThanOrEqualTo($course->end_date)) {
            foreach ($schedules as $schedule) {
                if ((int) $cursor->dayOfWeek === (int) $schedule->day_of_week) {
                    // start_time أُدخِل بمنطقة المركز وقت إنشاء الدورة (Course::schedule_timezone) — لا UTC ساذجة
                    $dates[] = Carbon::parse($cursor->toDateString(), $course->schedule_timezone ?? 'UTC')
                        ->setTimeFromTimeString($schedule->start_time)
                        ->setTimezone(config('app.timezone'));
                }
            }

            $cursor->addDay();
        }

        return collect($dates)->values()->map(function (Carbon $scheduledAt, int $index) use ($course) {
            return $course->sessions()->create([
                'teacher_id' => $course->teacher_id,
                'sequence_no' => $index + 1,
                'scheduled_at' => $scheduledAt,
                'duration_min' => $course->session_duration_min,
                'status' => 'scheduled',
            ]);
        });
    }
}
