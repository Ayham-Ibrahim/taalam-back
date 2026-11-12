<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Package;
use App\Models\SessionAttendee;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\BookingSubscriptionEnded;
use App\Services\SubscriptionEndedNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * بريد انتهاء الاشتراك: حين تنتهي كل جلسات الحجز يصل لبريد حساب الطالب (أو الحساب الأساسي
 * لحساب الأخ) مرة واحدة، مع رابط بروفايل المعلم للتجديد.
 */
class SubscriptionEndedEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_is_emailed_once_when_the_last_session_ends(): void
    {
        Notification::fake();
        [$teacher, $booking, $student] = $this->createBooking();
        $this->addSession($booking, now()->subDays(2));
        $this->addSession($booking, now()->subMinutes(70));

        $service = app(SubscriptionEndedNotificationService::class);
        $this->assertSame(1, $service->sendPending());

        Notification::assertSentTo($student->user, BookingSubscriptionEnded::class, function ($notification, $channels, $notifiable) use ($teacher) {
            $mail = $notification->toMail($notifiable);

            return $channels === ['mail']
                && $mail->actionUrl === frontend_url("/teacher/{$teacher->id}")
                && str_contains(implode(' ', $mail->introLines), 'باقة اختبار');
        });

        // مرة واحدة فقط
        $this->assertSame(0, $service->sendPending());
        $this->assertNotNull($booking->fresh()->subscription_ended_notified_at);
    }

    public function test_no_email_while_a_session_is_still_upcoming_or_in_progress(): void
    {
        Notification::fake();
        [, $booking] = $this->createBooking();
        $this->addSession($booking, now()->subDays(1));
        $this->addSession($booking, now()->subMinutes(30)); // جارية الآن

        $this->assertSame(0, app(SubscriptionEndedNotificationService::class)->sendPending());

        [, $other] = $this->createBooking();
        $this->addSession($other, now()->subDays(1));
        $this->addSession($other, now()->addDays(3)); // قادمة

        $this->assertSame(0, app(SubscriptionEndedNotificationService::class)->sendPending());
        Notification::assertNothingSent();
    }

    public function test_cancelled_upcoming_session_does_not_keep_the_subscription_open(): void
    {
        Notification::fake();
        [, $booking, $student] = $this->createBooking();
        $this->addSession($booking, now()->subHours(3));
        $this->addSession($booking, now()->addDays(2), 'cancelled');

        $this->assertSame(1, app(SubscriptionEndedNotificationService::class)->sendPending());
        Notification::assertSentTo($student->user, BookingSubscriptionEnded::class);
    }

    public function test_unpaid_and_long_ended_bookings_are_skipped(): void
    {
        Notification::fake();

        [, $unpaid] = $this->createBooking('pending_payment');
        $this->addSession($unpaid, now()->subHours(3));

        // انتهى قبل أكثر من 7 أيام — لا بريد عن الاشتراكات القديمة بعد النشر
        [, $old] = $this->createBooking();
        $this->addSession($old, now()->subDays(10));

        $this->assertSame(0, app(SubscriptionEndedNotificationService::class)->sendPending());
        Notification::assertNothingSent();
    }

    public function test_sibling_account_without_email_goes_to_the_primary_account(): void
    {
        Notification::fake();
        $primary = User::factory()->student()->create();
        $siblingUser = User::factory()->student()->create(['email' => null, 'primary_user_id' => $primary->id]);
        $sibling = Student::create(['user_id' => $siblingUser->id, 'education_type' => 'school']);

        [, $booking] = $this->createBooking('confirmed', $sibling);
        $this->addSession($booking, now()->subHours(2));

        $this->assertSame(1, app(SubscriptionEndedNotificationService::class)->sendPending());
        Notification::assertSentTo($primary, BookingSubscriptionEnded::class);
    }

    /**
     * @return array{0: Teacher, 1: Booking, 2: Student}
     */
    private function createBooking(string $status = 'confirmed', ?Student $student = null): array
    {
        $teacher = Teacher::create(['user_id' => User::factory()->teacher()->create()->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $subject = Subject::create(['code' => 's-'.uniqid(), 'name_ar' => 'رياضيات']);
        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة اختبار',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 2,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 320,
            'platform_revenue' => 120,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $student ??= Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']);

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 320,
            'teacher_amount' => 200,
            'platform_amount' => 120,
            'margin_percent_snapshot' => 60,
            'sessions_total' => 2,
            'sessions_remaining' => 2,
            'status' => $status,
        ]);

        return [$teacher, $booking, $student];
    }

    private function addSession(Booking $booking, $scheduledAt, string $status = 'scheduled'): ClassSession
    {
        $session = ClassSession::create([
            'booking_id' => $booking->id,
            'teacher_id' => $booking->teacher_id,
            'sequence_no' => $booking->sessions()->count() + 1,
            'scheduled_at' => $scheduledAt,
            'duration_min' => 60,
            'status' => $status,
        ]);

        SessionAttendee::create([
            'class_session_id' => $session->id,
            'student_id' => $booking->student_id,
            'booking_id' => $booking->id,
            'attendance' => 'registered',
        ]);

        return $session;
    }
}
