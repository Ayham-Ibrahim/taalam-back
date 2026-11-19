<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\User;
use App\Notifications\SubscriptionEndingSoon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إشعار الأدمن بقرب انتهاء اشتراك طالب: حجز مدفوع عُقدت جلسة واحدة منه على الأقل وبقيت له
 * جلسة واحدة لم تنتهِ — نفس تعريف الجلسات في SubscriptionEndedNotificationService (عبر أسطر
 * الحضور، والملغاة لا تُحسب)، فيصل قبل بريد انتهاء الاشتراك للطالب بجلسة. مرة واحدة لكل حجز
 * (bookings.ending_soon_notified_at)؛ باقة بجلسة واحدة لا تمرّ بهذه المرحلة فلا إشعار لها.
 */
class SubscriptionEndingSoonNotificationService
{
    private const PAID_STATUSES = ['confirmed', 'active', 'completed'];

    private const VOID_SESSION_STATUSES = ['cancelled'];

    public function __construct(private readonly NotificationService $notifications) {}

    public function sendPending(): int
    {
        $now = now();
        $endExpression = 'DATE_ADD(class_sessions.scheduled_at, INTERVAL class_sessions.duration_min MINUTE)';
        $notVoid = fn ($s) => $s->whereNotIn('class_sessions.status', self::VOID_SESSION_STATUSES);
        $sent = 0;

        $admins = User::query()->where('role', 'admin')->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            return 0;
        }

        Booking::query()
            ->whereIn('status', self::PAID_STATUSES)
            ->whereNull('ending_soon_notified_at')
            ->whereNull('subscription_ended_notified_at')
            // عُقدت (انتهت) جلسة واحدة على الأقل
            ->whereHas('attendees.session', fn ($s) => $notVoid($s)->whereRaw("{$endExpression} <= ?", [$now]))
            // وبقيت جلسة واحدة بالضبط لم تنتهِ (قادمة أو جارية، بما فيها التعويضية والمؤجَّلة) — العدّ
            // على أسطر الحضور (سطر لكل جلسة للحجز)؛ has('attendees.session', '=', 1) يعدّ داخل كل سطر
            ->whereHas('attendees', fn ($a) => $a->whereHas('session', fn ($s) => $notVoid($s)->whereRaw("{$endExpression} > ?", [$now])), '=', 1)
            ->with(['student.user:id,name,phone', 'package:id,title', 'teacher.user:id,name'])
            ->chunkById(100, function ($bookings) use ($admins, $notVoid, $endExpression, $now, &$sent) {
                foreach ($bookings as $booking) {
                    // يُعلَّم أولاً — فشل إرسال لا يعيده كل دورة
                    $claimed = Booking::whereKey($booking->id)
                        ->whereNull('ending_soon_notified_at')
                        ->update(['ending_soon_notified_at' => now()]);

                    if (! $claimed) {
                        continue;
                    }

                    $lastSession = ClassSession::query()
                        ->whereHas('attendees', fn ($a) => $a->where('booking_id', $booking->id))
                        ->where($notVoid)
                        ->whereRaw("{$endExpression} > ?", [$now])
                        ->orderBy('scheduled_at')
                        ->first();

                    if ($lastSession && $this->notifyAdmins($admins, $booking, $lastSession)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function notifyAdmins(Collection $admins, Booking $booking, ClassSession $lastSession): bool
    {
        $student = $booking->student;
        $delivered = false;

        foreach ($admins as $admin) {
            try {
                // نسخة لكل مستلم — TracksNotificationLog يحفظ أرقام سجلاته على الكائن نفسه
                $this->notifications->send($admin, new SubscriptionEndingSoon(
                    $booking->id,
                    $booking->student_id,
                    $student?->user?->name ?? '',
                    $student?->user?->phone ?: $student?->guardian_phone,
                    $booking->package?->title ?? '',
                    $booking->teacher?->user?->name ?? '',
                    $lastSession->scheduled_at,
                ), 'booking.subscription_ending_soon');

                $delivered = true;
            } catch (Throwable $e) {
                Log::error('فشل إرسال إشعار قرب انتهاء الاشتراك للأدمن', [
                    'booking_id' => $booking->id,
                    'admin_id' => $admin->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $delivered;
    }
}
