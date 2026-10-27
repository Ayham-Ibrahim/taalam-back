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
    /**
     * نتائج الحضور التي تستهلك مكاناً من السقف — total_sessions للدورة، وsessions_total لكل
     * حجز باقة: جلسة عُقدت فعلاً، أو بلا دليل غياب (untracked/NULL قبل الحضور التلقائي).
     * student_no_show تُصرف بنسبتها خارج السقف، وteacher_absent/no_paid_attendee لا تُصرف
     * ولا تستهلك مكاناً.
     */
    private const SLOT_OUTCOMES = [ClassSession::OUTCOME_ATTENDED, ClassSession::OUTCOME_UNTRACKED];

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

        $settlements = $this->lateJoinSettlements($teacher);

        if ($sessions->isEmpty() && $settlements === []) {
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

        $amounts = $this->payableAmounts($sessions, afterPreviousPayouts: true);

        $gross = 0.0;
        $count = 0;
        $now = now();
        $items = [];

        foreach ($sessions as $session) {
            // صفر: غياب المعلم، أو بلا اشتراك مدفوع، أو كلها خارج السقف — لا بند لها
            $amount = $amounts[$session->id]['amount'] ?? 0.0;

            if ($amount <= 0) {
                continue;
            }

            $items[] = [
                'payout_id' => $payout->id,
                'class_session_id' => $session->id,
                'type' => PayoutItem::TYPE_SESSION,
                'amount' => $amount,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $gross += $amount;
            $count++;
        }

        // تسويات التسجيل/الانضمام المتأخر تُضاف لأول دفعة تالية بصرف النظر عن فترتها — جلساتها صُرفت سابقاً
        foreach ($settlements as $sessionId => $amount) {
            $items[] = [
                'payout_id' => $payout->id,
                'class_session_id' => $sessionId,
                'type' => PayoutItem::TYPE_SETTLEMENT,
                'amount' => $amount,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $gross += $amount;
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
     * طالب يسجّل في دورة، أو ينضم لباقة جماعية، بعد صرف بعض جلساتها يدفع سعرها كاملاً
     * ويُربط بكل جلساتها (BookingService::sessionsFor / EnrollmentService)، لكن تلك الجلسات
     * مستبعدة من التوليد (مصروفة) — فتضيع حصته منها على المعلم/المركز. وكذلك حجز جماعي
     * كان pending_payment وقت صرف الجلسة ثم دُفع. هنا يُعاد حساب كل جلسة دورة أو باقة
     * جماعية مصروفة بالاشتراكات المدفوعة الحالية بنفس نسبة حضورها المثبَّتة، ويُرجع الفرق
     * عمّا صُرف لها فعلاً (كل بنودها السابقة، جلسة وتسويات). لا فرق سالب — الصرف السابق
     * لا يُسترد. الباقة الفردية مستثناة: حجز واحد ثابت لا ينضم إليه أحد. أربعة استعلامات
     * ثابتة بغض النظر عن عدد الجلسات.
     *
     * @return array<int, float> [class_session_id => amount]
     */
    private function lateJoinSettlements(Teacher $teacher): array
    {
        $paidBySession = PayoutItem::query()
            ->whereHas('payout', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->whereHas('session', fn ($q) => $q
                ->whereNotNull('course_id')
                ->orWhereHas('booking.package', fn ($p) => $p->where('session_format', 'group')))
            ->selectRaw('class_session_id, SUM(amount) as paid')
            ->groupBy('class_session_id')
            ->pluck('paid', 'class_session_id');

        if ($paidBySession->isEmpty()) {
            return [];
        }

        $sessions = ClassSession::whereIn('id', $paidBySession->keys())->with('course')->get();
        // السقف على كل الجلسات المصروفة معاً من الصفر (لا "مستهلك سابقاً" — هي نفسها السابقة):
        // جلسات صُرفت قبل تطبيق السقف وتتجاوزه لا تُضخَّم بتسوية
        $amounts = $this->payableAmounts($sessions, afterPreviousPayouts: false);
        $settlements = [];

        foreach ($sessions as $session) {
            $due = $amounts[$session->id]['amount'] ?? 0.0;
            $difference = round($due - (float) $paidBySession[$session->id], 2);

            if ($difference > 0) {
                $settlements[$session->id] = $difference;
            }
        }

        return $settlements;
    }

    /**
     * المركز يُصرف له عن total_sessions جلسة عقدها فعلاً لا أكثر، أياً كان رقمها: جلسة غاب
     * عنها لا تستهلك مكاناً، فتملؤه جلسة لاحقة عقدها (تعويضية، أو زائدة في دورة قديمة
     * وُلِّدت لها جلسات أكثر من العدد المعلن). الأماكن تُوزَّع بترتيب الموعد بعد خصم ما
     * استُهلك في دفعات سابقة ($usedSlots).
     *
     * @param  Collection<int|string, int>  $usedSlots  [course_id => used]
     * @return array<int, true> [class_session_id => true] جلسات لا مكان لها
     */
    private function sessionsBeyondCourseCap(Collection $sessions, Collection $usedSlots): array
    {
        $beyond = [];

        $slotSessions = $sessions
            ->filter(fn (ClassSession $s) => $s->course_id !== null && $this->consumesSlot($s))
            ->sortBy('scheduled_at')
            ->groupBy('course_id');

        foreach ($slotSessions as $courseId => $group) {
            $available = max(0, ($group->first()->course?->total_sessions ?? 0) - (int) ($usedSlots[$courseId] ?? 0));

            foreach ($group as $session) {
                if ($available > 0) {
                    $available--;

                    continue;
                }

                $beyond[$session->id] = true;
            }
        }

        return $beyond;
    }

    /**
     * الأماكن المستهلكة في دفعات سابقة لكل دورة من دورات هذه الجلسات — بنود الجلسات
     * العادية فقط (التسوية ليست جلسة إضافية).
     *
     * @return Collection<int|string, int> [course_id => used]
     */
    private function usedCourseSlots(Collection $sessions): Collection
    {
        $courseIds = $sessions->pluck('course_id')->filter()->unique();

        if ($courseIds->isEmpty()) {
            return collect();
        }

        return PayoutItem::query()
            ->join('class_sessions', 'class_sessions.id', '=', 'payout_items.class_session_id')
            ->where('payout_items.type', PayoutItem::TYPE_SESSION)
            ->whereIn('class_sessions.course_id', $courseIds)
            ->where(fn ($q) => $q
                ->whereNull('class_sessions.attendance_outcome')
                ->orWhereIn('class_sessions.attendance_outcome', self::SLOT_OUTCOMES))
            ->selectRaw('class_sessions.course_id, COUNT(*) as used')
            ->groupBy('class_sessions.course_id')
            ->pluck('used', 'course_id');
    }

    /**
     * نظير سقف الدورة لكل حجز باقة (فردية أو جماعية): حصة الحجز تُصرف عن sessions_total
     * جلسة عقدها المعلم فعلاً لا أكثر — تعويضية بعد غياب المعلم تملأ مكان الجلسة الفائتة،
     * وما زاد (تعويضية جلسة عُقدت، جلسات زائدة في بيانات قديمة) لا يُصرف. student_no_show
     * خارج السقف كالدورة. السقف على الحصة لا الجلسة: جلسة مجموعة تجاوز فيها حجز واحد
     * سقفه تُصرف بحصص البقية.
     *
     * @param  array<int, array<int, float>>  $shares  [session_id => [booking_id => share]]
     * @param  array<int, int>  $limits  [booking_id => sessions_total]
     * @param  array<int, int>  $usedSlots  [booking_id => used]
     * @return array<int, array<int, true>> [session_id => [booking_id => true]] حصص لا مكان لها
     */
    private function sharesBeyondBookingCap(Collection $sessions, array $shares, array $limits, array $usedSlots): array
    {
        $available = [];

        foreach ($limits as $bookingId => $limit) {
            $available[$bookingId] = max(0, $limit - ($usedSlots[$bookingId] ?? 0));
        }

        $beyond = [];

        $slotSessions = $sessions
            ->filter(fn (ClassSession $s) => $s->course_id === null && $this->consumesSlot($s))
            ->sortBy([['scheduled_at', 'asc'], ['id', 'asc']]);

        foreach ($slotSessions as $session) {
            foreach (array_keys($shares[$session->id] ?? []) as $bookingId) {
                if ($available[$bookingId] > 0) {
                    $available[$bookingId]--;

                    continue;
                }

                $beyond[$session->id][$bookingId] = true;
            }
        }

        return $beyond;
    }

    /**
     * أماكن كل حجز المستهلكة في دفعات سابقة: جلسات باقة مصروفة (بند جلسة لا تسوية) تستهلك
     * مكاناً وحصة الحجز محتسبة فيها — بنفس bookingShares كي يطابق العدُّ التوزيع.
     *
     * @param  array<int, int>  $bookingIds
     * @return array<int, int> [booking_id => used]
     */
    private function usedBookingSlots(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $paidSessions = ClassSession::query()
            ->whereNull('course_id')
            ->whereIn('id', PayoutItem::where('type', PayoutItem::TYPE_SESSION)->select('class_session_id'))
            ->where(fn ($q) => $q
                ->whereNull('attendance_outcome')
                ->orWhereIn('attendance_outcome', self::SLOT_OUTCOMES))
            ->where(fn ($q) => $q
                ->whereIn('booking_id', $bookingIds)
                ->orWhereHas('attendees', fn ($a) => $a->whereIn('booking_id', $bookingIds)))
            ->get(['id', 'booking_id', 'scheduled_at']);

        $used = [];

        foreach ($this->bookingShares($paidSessions)['shares'] as $sessionShares) {
            foreach (array_keys($sessionShares) as $bookingId) {
                $used[$bookingId] = ($used[$bookingId] ?? 0) + 1;
            }
        }

        return $used;
    }

    private function consumesSlot(ClassSession $session): bool
    {
        return $session->attendance_outcome === null
            || in_array($session->attendance_outcome, self::SLOT_OUTCOMES, true);
    }

    /**
     * مستحق المعلم من كل جلسة للعرض على الأدمن (قائمة الجلسات): المصروفة → مجموع بنودها
     * الفعلية (جلسة + تسويات)، والمكتملة غير المصروفة → المتوقع بنفس payableAmounts (النسبة
     * والسقفان). السقف يُوزَّع زمنياً، فيُحسب على كل الجلسات المكتملة غير المصروفة لدورات/حجوزات
     * هذه الصفحة لا على الصفحة وحدها. capped = سقط منها شيء بالسقف. غير المكتملة لا مبلغ لها.
     *
     * @return array<int, array{amount: float, paid: bool, capped: bool}> [class_session_id => ...]
     */
    public function sessionPayoutAmounts(Collection $sessions): array
    {
        $ids = $sessions->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $paidBySession = PayoutItem::whereIn('class_session_id', $ids)
            ->selectRaw('class_session_id, SUM(amount) as paid')
            ->groupBy('class_session_id')
            ->pluck('paid', 'class_session_id');

        $result = [];

        foreach ($paidBySession as $sessionId => $paid) {
            $result[$sessionId] = ['amount' => round((float) $paid, 2), 'paid' => true, 'capped' => false];
        }

        $pending = ClassSession::whereIn('id', $ids->diff($paidBySession->keys()))
            ->where('status', 'completed')
            ->get();

        if ($pending->isEmpty()) {
            return $result;
        }

        $courseIds = $pending->pluck('course_id')->filter()->unique()->values();
        $bookingIds = array_keys($this->bookingShares($pending)['limits']);

        $related = ClassSession::query()
            ->where('status', 'completed')
            ->whereNotIn('id', PayoutItem::select('class_session_id'))
            ->where(fn ($q) => $q
                ->whereIn('id', $pending->pluck('id'))
                ->orWhereIn('course_id', $courseIds)
                ->orWhereIn('booking_id', $bookingIds)
                ->orWhereHas('attendees', fn ($a) => $a->whereIn('booking_id', $bookingIds)))
            ->with('course')
            ->get();

        $amounts = $this->payableAmounts($related, afterPreviousPayouts: true);

        foreach ($pending as $session) {
            $result[$session->id] = [
                'amount' => $amounts[$session->id]['amount'] ?? 0.0,
                'paid' => false,
                'capped' => $amounts[$session->id]['capped'] ?? false,
            ];
        }

        return $result;
    }

    /**
     * نسبة الحضور التلقائي المثبَّتة عند انتهاء الجلسة (SessionService::completeEndedSessions):
     * جزئية عند غياب الطالب، صفر عند غياب المعلم. NULL = جلسة لم تُقيَّم (قبل الحضور التلقائي) → كاملة
     */
    private function payablePercent(ClassSession $session): float
    {
        return $session->teacher_payout_percent === null ? 100.0 : (float) $session->teacher_payout_percent;
    }

    /**
     * مستحق المعلم الفعلي من كل جلسة: قيمتها × نسبة حضورها، بعد السقفين — جلسة الدورة
     * خارج سقف الدورة صفر، وجلسة الباقة تسقط منها حصة كل حجز خارج سقفه. capped = سقط منها
     * شيء بالسقف. $afterPreviousPayouts: الأماكن تُوزَّع بعد ما استُهلك في دفعات سابقة (توليد
     * وعرض)، أو من الصفر على الجلسات المعطاة نفسها (تسويات: هي كل المصروف). أربعة استعلامات
     * ثابتة بغض النظر عن عدد الجلسات، وأربعة أخرى لعدّ الأماكن المستهلكة.
     *
     * @return array<int, array{amount: float, capped: bool}> [class_session_id => ...]
     */
    private function payableAmounts(Collection $sessions, bool $afterPreviousPayouts): array
    {
        $courseValues = $this->courseSessionValues($sessions);
        ['shares' => $shares, 'limits' => $limits] = $this->bookingShares($sessions);

        $beyondCourse = $this->sessionsBeyondCourseCap($sessions, $afterPreviousPayouts ? $this->usedCourseSlots($sessions) : collect());
        $beyondBooking = $this->sharesBeyondBookingCap(
            $sessions,
            $shares,
            $limits,
            $afterPreviousPayouts ? $this->usedBookingSlots(array_keys($limits)) : [],
        );

        $result = [];

        foreach ($sessions as $session) {
            if ($session->course_id !== null) {
                $capped = isset($beyondCourse[$session->id]);
                $value = $capped ? 0.0 : ($courseValues[$session->id] ?? 0.0);
            } else {
                $excluded = $beyondBooking[$session->id] ?? [];
                $capped = $excluded !== [];
                $value = array_sum(array_diff_key($shares[$session->id] ?? [], $excluded));
            }

            $result[$session->id] = [
                'amount' => round($value * $this->payablePercent($session) / 100, 2),
                'capped' => $capped,
            ];
        }

        return $result;
    }

    /**
     * دورة: مستحق المركز الكلي من كل تسجيلات الدورة المدفوعة ÷ عدد جلساتها — مرة واحدة
     * لكل دورة لا لكل جلسة.
     *
     * @return array<int, float> [class_session_id => value]
     */
    private function courseSessionValues(Collection $sessions): array
    {
        $amounts = [];

        $courseSessions = $sessions->filter(fn (ClassSession $s) => $s->course_id !== null);

        if ($courseSessions->isNotEmpty()) {
            $courseIds = $courseSessions->pluck('course_id')->unique();

            $revenueByCourseId = Enrollment::whereIn('course_id', $courseIds)
                ->whereIn('status', ClassSession::PAID_ENROLLMENT_STATUSES)
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

        return $amounts;
    }

    /**
     * باقة: حصة كل حجز مدفوع يحضر الجلسة (فردي، أو حجوزات المجموعة عبر session_attendees)
     * = مستحقه ÷ عدد جلساته. قيمة الجلسة مجموع حصصها — منفصلة كي يُطبَّق سقف كل حجز على حصته.
     *
     * @return array{shares: array<int, array<int, float>>, limits: array<int, int>}
     *         shares: [session_id => [booking_id => share]]، limits: [booking_id => sessions_total]
     */
    private function bookingShares(Collection $sessions): array
    {
        $shares = [];
        $limits = [];

        $bookingSessions = $sessions->filter(fn (ClassSession $s) => $s->course_id === null);

        if ($bookingSessions->isEmpty()) {
            return ['shares' => $shares, 'limits' => $limits];
        }

        $bookingIdsBySession = ClassSession::payableBookingIdsBySession($bookingSessions);

        $allBookingIds = $bookingIdsBySession->flatten()
            ->merge($bookingSessions->pluck('booking_id')->filter())
            ->unique();

        // سطر الحضور يبقى بعد انتهاء/إلغاء الحجز — المدفوع فقط يُحتسب مستحقاً
        $bookingsById = Booking::whereIn('id', $allBookingIds)
            ->whereIn('status', ClassSession::PAID_BOOKING_STATUSES)
            ->where('sessions_total', '>', 0)
            ->get()
            ->keyBy('id');

        foreach ($bookingSessions as $session) {
            $bookingIds = $bookingIdsBySession->get($session->id, collect());

            if (! $bookingIdsBySession->has($session->id) && $session->booking_id) {
                $bookingIds = collect([$session->booking_id]);
            }

            $shares[$session->id] = [];

            foreach ($bookingIds as $bookingId) {
                $booking = $bookingsById->get($bookingId);

                if ($booking) {
                    $shares[$session->id][$bookingId] = ((float) $booking->teacher_amount) / $booking->sessions_total;
                    $limits[$bookingId] = (int) $booking->sessions_total;
                }
            }
        }

        return ['shares' => $shares, 'limits' => $limits];
    }
}
