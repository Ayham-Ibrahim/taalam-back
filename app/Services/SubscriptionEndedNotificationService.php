<?php

namespace App\Services;

use App\Models\Booking;
use App\Notifications\BookingSubscriptionEnded;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * بريد انتهاء اشتراك الطالب بباقة: الاشتراك "منتهٍ" حين تنتهي كل جلساته (نفس تعريف
 * BookingService::assertNoOpenBookingForPackage الذي يسمح عندها بالاشتراك من جديد) — لا حين
 * يصير الحجز completed، فذلك لا يحدث إلا بتسجيل الحضور اليدوي. يُرسَل مرة واحدة لكل حجز
 * (bookings.subscription_ended_notified_at).
 */
class SubscriptionEndedNotificationService
{
    /** حجوزات مدفوعة فقط — الملغى/المنتهي المهلة لم يكن اشتراكاً فعلياً */
    private const PAID_STATUSES = ['confirmed', 'active', 'completed'];

    /** جلسات لا تُحسب (لم تُعقد) — لا تجعل الاشتراك مفتوحاً */
    private const VOID_SESSION_STATUSES = ['cancelled'];

    /**
     * لا بريد لاشتراك انتهت آخر جلساته قبل هذه المدة — حتى لا يصل بريد عن كل الاشتراكات
     * القديمة عند أول تشغيل بعد النشر. الطبيعي أن يُرسَل خلال دقائق من انتهاء آخر جلسة.
     */
    private const MAX_DELAY_DAYS = 7;

    public function __construct(private readonly NotificationService $notifications) {}

    public function sendPending(): int
    {
        $now = now();
        $endExpression = 'DATE_ADD(class_sessions.scheduled_at, INTERVAL class_sessions.duration_min MINUTE)';
        $sent = 0;

        Booking::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->whereNull('subscription_ended_notified_at')
            // انتهت آخر جلسة له مؤخراً (ضمن MAX_DELAY_DAYS)
            ->whereHas('attendees.session', fn ($s) => $s
                ->whereNotIn('class_sessions.status', self::VOID_SESSION_STATUSES)
                ->whereRaw("{$endExpression} >= ?", [$now->clone()->subDays(self::MAX_DELAY_DAYS)]))
            // ولا جلسة له لم تنتهِ بعد (قادمة أو جارية، بما فيها التعويضية والمؤجَّلة)
            ->whereDoesntHave('attendees.session', fn ($s) => $s
                ->whereNotIn('class_sessions.status', self::VOID_SESSION_STATUSES)
                ->whereRaw("{$endExpression} > ?", [$now]))
            ->with(['student.user.primaryAccount', 'package:id,title', 'teacher.user:id,name'])
            ->chunkById(100, function ($bookings) use (&$sent) {
                foreach ($bookings as $booking) {
                    // يُعلَّم أولاً — فشل بريد واحد لا يعيد إرساله كل دورة
                    $claimed = Booking::whereKey($booking->id)
                        ->whereNull('subscription_ended_notified_at')
                        ->update(['subscription_ended_notified_at' => now()]);

                    $recipient = $this->recipientFor($booking);

                    if ($claimed && $recipient && $this->sendSafely($recipient, $booking)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /**
     * بريد حساب الطالب (لا بريد منفصل لولي الأمر في المنصة). حساب الأخ بلا بريد → الحساب
     * الأساسي الذي أضافه.
     */
    private function recipientFor(Booking $booking)
    {
        $user = $booking->student?->user;

        if (! $user) {
            return null;
        }

        return $user->email ? $user : $user->primaryAccount;
    }

    private function sendSafely($recipient, Booking $booking): bool
    {
        try {
            $this->notifications->send($recipient, new BookingSubscriptionEnded($booking), 'booking.subscription_ended');

            return true;
        } catch (Throwable $e) {
            Log::error('فشل إرسال بريد انتهاء الاشتراك', [
                'booking_id' => $booking->id,
                'recipient_id' => $recipient->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
