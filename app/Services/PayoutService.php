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
use Illuminate\Support\Collection;
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

        $amounts = $this->calculateSessionAmounts($sessions);

        $gross = 0.0;
        $count = 0;
        $now = now();
        $items = [];

        foreach ($sessions as $session) {
            $amount = $amounts[$session->id] ?? 0.0;

            if ($amount <= 0) {
                continue;
            }

            $items[] = [
                'payout_id' => $payout->id,
                'class_session_id' => $session->id,
                'amount' => round($amount, 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $gross += $amount;
            $count++;
        }

        if ($items !== []) {
            PayoutItem::insert($items);
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
     * مبلغ كل جلسة دفعة واحدة لكل الجلسات معاً — بدل استعلامين أو أكثر *لكل جلسة*
     * (كان معلم بمئات الجلسات في فترة واحدة يولّد مئات الاستعلامات). أربعة استعلامات
     * ثابتة بغض النظر عن العدد:
     *   دورة: مستحق المركز الكلي من كل تسجيلات الدورة النشطة ÷ عدد جلساتها —
     *     يُحسب مرة واحدة لكل دورة (لا لكل جلسة، حتى لو تكررت الدورة عبر عدة جلسات).
     *   باقة: مستحق كل حجز يحضر هذه الجلسة (فردي أو حجوزات المجموعة المشتركة عبر
     *     session_attendees) ÷ عدد جلسات ذلك الحجز، مجموعة على بعضها.
     *
     * @return array<int, float> [class_session_id => amount]
     */
    private function calculateSessionAmounts(Collection $sessions): array
    {
        $amounts = [];

        $courseSessions = $sessions->filter(fn (ClassSession $s) => $s->course_id !== null);

        if ($courseSessions->isNotEmpty()) {
            $courseIds = $courseSessions->pluck('course_id')->unique();

            $revenueByCourseId = Enrollment::whereIn('course_id', $courseIds)
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->selectRaw('course_id, SUM(provider_amount) as total')
                ->groupBy('course_id')
                ->pluck('total', 'course_id');

            foreach ($courseSessions as $session) {
                $course = $session->course;

                $amounts[$session->id] = ($course && $course->total_sessions > 0)
                    ? ((float) ($revenueByCourseId[$course->id] ?? 0)) / $course->total_sessions
                    : 0.0;
            }
        }

        $bookingSessions = $sessions->filter(fn (ClassSession $s) => $s->course_id === null);

        if ($bookingSessions->isNotEmpty()) {
            $sessionIds = $bookingSessions->pluck('id');

            $bookingIdsBySession = SessionAttendee::whereIn('class_session_id', $sessionIds)
                ->whereNotNull('booking_id')
                ->get(['class_session_id', 'booking_id'])
                ->groupBy('class_session_id')
                ->map(fn ($rows) => $rows->pluck('booking_id')->unique());

            $allBookingIds = $bookingIdsBySession->flatten()
                ->merge($bookingSessions->pluck('booking_id')->filter())
                ->unique();

            $bookingsById = Booking::whereIn('id', $allBookingIds)->get()->keyBy('id');

            foreach ($bookingSessions as $session) {
                $bookingIds = $bookingIdsBySession->get($session->id, collect());

                if ($bookingIds->isEmpty() && $session->booking_id) {
                    $bookingIds = collect([$session->booking_id]);
                }

                $total = 0.0;

                foreach ($bookingIds as $bookingId) {
                    $booking = $bookingsById->get($bookingId);

                    if ($booking && $booking->sessions_total > 0) {
                        $total += ((float) $booking->teacher_amount) / $booking->sessions_total;
                    }
                }

                $amounts[$session->id] = $total;
            }
        }

        return $amounts;
    }
}
