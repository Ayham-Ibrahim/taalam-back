<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\SessionAttendee;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PayoutService
{
    public function generateForPeriod(Teacher $teacher, Carbon $periodStart, Carbon $periodEnd): Payout
    {
        $alreadyPaidSessionIds = PayoutItem::whereHas('payout', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->pluck('class_session_id');

        $sessions = ClassSession::where('teacher_id', $teacher->id)
            ->where('status', 'completed')
            ->whereBetween('scheduled_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->whereNotIn('id', $alreadyPaidSessionIds)
            ->with('course')
            ->get();

        if ($sessions->isEmpty()) {
            throw ValidationException::withMessages([
                'period' => ['لا توجد جلسات مكتملة غير مدفوعة لهذا المعلم ضمن هذه الفترة'],
            ]);
        }

        $payout = Payout::create([
            'teacher_id' => $teacher->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'gross_amount' => 0,
            'net_amount' => 0,
            'sessions_count' => 0,
            'status' => 'pending',
        ]);

        $gross = 0.0;
        $count = 0;

        foreach ($sessions as $session) {
            $amount = $this->calculateSessionAmount($session);

            if ($amount <= 0) {
                continue;
            }

            PayoutItem::create([
                'payout_id' => $payout->id,
                'class_session_id' => $session->id,
                'amount' => round($amount, 2),
            ]);

            $gross += $amount;
            $count++;
        }

        $payout->update([
            'gross_amount' => round($gross, 2),
            'net_amount' => round($gross, 2),
            'sessions_count' => $count,
        ]);

        return $payout->fresh(['items']);
    }

    public function approve(Payout $payout, User $admin): Payout
    {
        if ($payout->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['يمكن اعتماد المستحقات فقط من حالة قيد الانتظار'],
            ]);
        }

        $payout->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);

        return $payout->fresh();
    }

    public function markPaid(Payout $payout, string $transferReference): Payout
    {
        if ($payout->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => ['يجب اعتماد المستحقات أولاً قبل تحويلها'],
            ]);
        }

        $payout->update([
            'status' => 'paid',
            'paid_at' => now(),
            'transfer_reference' => $transferReference,
        ]);

        return $payout->fresh();
    }

    /**
     * دورة: مستحق المركز الكلي من كل تسجيلات الدورة النشطة ÷ عدد جلساتها.
     * باقة: مستحق كل حجز يحضر هذه الجلسة (فردي أو حجوزات المجموعة المشتركة عبر
     * session_attendees) ÷ عدد جلسات ذلك الحجز، مجموعة على بعضها.
     */
    private function calculateSessionAmount(ClassSession $session): float
    {
        if ($session->course_id) {
            $course = $session->course;

            if (! $course || $course->total_sessions <= 0) {
                return 0.0;
            }

            $providerRevenue = (float) Enrollment::where('course_id', $course->id)
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->sum('provider_amount');

            return $providerRevenue / $course->total_sessions;
        }

        $bookingIds = SessionAttendee::where('class_session_id', $session->id)
            ->pluck('booking_id')
            ->filter()
            ->unique();

        if ($bookingIds->isEmpty() && $session->booking_id) {
            $bookingIds = collect([$session->booking_id]);
        }

        $total = 0.0;

        foreach (Booking::whereIn('id', $bookingIds)->get() as $booking) {
            if ($booking->sessions_total > 0) {
                $total += ((float) $booking->teacher_amount) / $booking->sessions_total;
            }
        }

        return $total;
    }
}
