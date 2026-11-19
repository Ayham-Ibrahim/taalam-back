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
use App\Notifications\SubscriptionEndingSoon;
use App\Services\SubscriptionEndingSoonNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * إشعار الأدمن بقرب انتهاء الاشتراك: حين تبقى جلسة واحدة لم تنتهِ في حجز مدفوع عُقد منه شيء،
 * يصل كل أدمن نشط (جرس + بريد) مرة واحدة لكل حجز.
 */
class SubscriptionEndingSoonNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_active_admin_is_notified_once_when_one_session_is_left(): void
    {
        Notification::fake();
        $admins = User::factory()->admin()->count(2)->create();
        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);

        [$booking, $student] = $this->createBooking(sessions: 3);
        $this->addSession($booking, now()->subDays(3));
        $this->addSession($booking, now()->subDays(1));
        $last = $this->addSession($booking, now()->addDays(2));

        $service = app(SubscriptionEndingSoonNotificationService::class);
        $this->assertSame(1, $service->sendPending());

        foreach ($admins as $admin) {
            Notification::assertSentTo($admin, SubscriptionEndingSoon::class, function ($notification, $channels, $notifiable) use ($booking, $student, $last) {
                $data = $notification->toArray($notifiable);

                return $channels === ['mail', 'database']
                    && $data['bookingId'] === $booking->id
                    && $data['studentId'] === $student->id
                    && $data['packageTitle'] === 'باقة اختبار'
                    && $data['lastSessionAt'] === $last->scheduled_at->toIso8601String()
                    && $notification->toMail($notifiable)->actionUrl === frontend_url("/dashboard/admin/students/{$student->id}");
            });
        }
        Notification::assertNotSentTo($inactiveAdmin, SubscriptionEndingSoon::class);

        // مرة واحدة فقط
        $this->assertSame(0, $service->sendPending());
        $this->assertNotNull($booking->fresh()->ending_soon_notified_at);
    }

    public function test_no_notification_while_more_than_one_session_is_left_or_none_was_held(): void
    {
        Notification::fake();
        User::factory()->admin()->create();

        [$twoLeft] = $this->createBooking(sessions: 3);
        $this->addSession($twoLeft, now()->subDay());
        $this->addSession($twoLeft, now()->addDay());
        $this->addSession($twoLeft, now()->addDays(2));

        // باقة بجلسة واحدة قادمة — لم يُعقد منها شيء بعد
        [$single] = $this->createBooking(sessions: 1);
        $this->addSession($single, now()->addDay());

        $this->assertSame(0, app(SubscriptionEndingSoonNotificationService::class)->sendPending());
        Notification::assertNothingSent();
    }

    public function test_cancelled_sessions_are_ignored_and_unpaid_bookings_skipped(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        // الملغاة لا تُعد — بقيت فعلياً جلسة واحدة
        [$booking] = $this->createBooking(sessions: 3);
        $this->addSession($booking, now()->subDay());
        $this->addSession($booking, now()->addDay());
        $this->addSession($booking, now()->addDays(2), 'cancelled');

        [$unpaid] = $this->createBooking(sessions: 2, status: 'expired');
        $this->addSession($unpaid, now()->subDay());
        $this->addSession($unpaid, now()->addDay());

        $this->assertSame(1, app(SubscriptionEndingSoonNotificationService::class)->sendPending());
        Notification::assertSentToTimes($admin, SubscriptionEndingSoon::class, 1);
    }

    /**
     * @return array{0: Booking, 1: Student}
     */
    private function createBooking(int $sessions, string $status = 'confirmed'): array
    {
        $teacher = Teacher::create(['user_id' => User::factory()->teacher()->create()->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة اختبار',
            'subject_id' => Subject::create(['code' => 's-'.uniqid(), 'name_ar' => 'رياضيات'])->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => $sessions,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160 * $sessions,
            'platform_revenue' => 60 * $sessions,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $student = Student::create(['user_id' => User::factory()->student()->create()->id, 'education_type' => 'school']);

        $booking = Booking::create([
            'reference' => 'BK-'.strtoupper(uniqid()),
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'package_id' => $package->id,
            'amount_paid' => 160 * $sessions,
            'teacher_amount' => 100 * $sessions,
            'platform_amount' => 60 * $sessions,
            'margin_percent_snapshot' => 60,
            'sessions_total' => $sessions,
            'sessions_remaining' => $sessions,
            'status' => $status,
        ]);

        return [$booking, $student];
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
