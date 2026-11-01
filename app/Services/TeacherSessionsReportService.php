<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\PayoutItem;
use App\Models\Teacher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * تقرير "كل مدرس: عدد الحصص والعائد" للمحاسب/الأدمن (لوحة المحاسبة) — على نفس حساب
 * المستحقات تماماً (PayoutService::sessionPayoutAmounts) كي يطابق الكشوف:
 *
 * - مستحق المعلم: الجلسة المصروفة → مجموع بنودها الفعلية (جلسة + تسويات تسجيل متأخر) +
 *   تسوية مستحقة لم تُولَّد بعد (PayoutService::lateJoinSettlements)، والمكتملة غير المصروفة
 *   → المتوقع بنسبة الحضور التلقائي والسقفين. فمجموعه = ما في الكشوف + ما سيدخل الكشف القادم
 *   (inPayoutsAmount + notInPayoutsAmount). التسوية تُنسب لتاريخ جلستها لا لتاريخ كشفها.
 * - الحصة "المحقَّقة": جلسة مكتملة لها مستحق للمعلم — عُقدت، أو دخلها المعلم ولم يحضر
 *   الطلاب (نسبة جزئية). غياب المعلم، أو بلا اشتراك مدفوع، أو خارج السقف لا تُعد.
 * - عائد المنصة: حصتها (platform_amount ÷ عدد الجلسات) من الجلسات التي تستهلك مكاناً من
 *   السقف فقط (attended/untracked) — فلا يتجاوز عائدها من حجز/دورة ما دُفع لها، ولا يُحتسب
 *   مرتين لجلسة لم يحضرها الطلاب ثم تعويضيتها. زائداً قيمة الجلسة الفائتة بلا تعويضية (غياب
 *   المعلم أو الطلاب): ما لم يُصرف للمعلم منها + حصة المنصة، ضمن السقف (forfeitedSessionAmounts)
 *   — تنتقل للمعلم بمجرد إنشاء تعويضية لها، فتتغيّر أرقام فترتها الماضية. ناقصاً تعويض غياب
 *   الطلاب الثابت (PayoutService::compensations، ولا تعويض لجلسة لها تعويضية) — يُدفع من قيمة
 *   الجلسة الفائتة التي بقيت للمنصة — وناقصاً ما صُرف للمعلم زيادةً على قيمة عقده من جلسات
 *   غياب الطلاب المحسومة بنسبة قبله (PayoutService::compensationBeyondContract). فمستحق المعلم
 *   + عائد المنصة = ما دفعه الطالب عن الجلسات المنتهية.
 *
 * كان يعتمد سابقاً على الحضور اليدوي (attendance=present/partial) الذي لا يُسجَّل عملياً
 * بعد الحضور التلقائي، وعلى قيمة الجلسة الكاملة بلا نسبة ولا سقف ولا تسويات.
 */
class TeacherSessionsReportService
{
    private const PLATFORM_SLOT_OUTCOMES = [ClassSession::OUTCOME_ATTENDED, ClassSession::OUTCOME_UNTRACKED];

    /** جلسة مدفوعة لم تُعقد — قيمتها تبقى للمنصة ما لم تُنشأ لها تعويضية */
    private const MISSED_OUTCOMES = [ClassSession::OUTCOME_TEACHER_ABSENT, ClassSession::OUTCOME_STUDENT_NO_SHOW];

    public function __construct(private readonly PayoutService $payouts) {}

    public function getReport(?Carbon $from, ?Carbon $to, ?int $teacherId = null): array
    {
        $completed = ClassSession::query()
            ->where('status', 'completed')
            ->when($from, fn ($q) => $q->where('scheduled_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('scheduled_at', '<=', $to->copy()->endOfDay()))
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
            ->with('course:id,total_sessions')
            ->get(['id', 'teacher_id', 'course_id', 'booking_id', 'scheduled_at', 'attendance_outcome', 'teacher_compensation_amount', 'makeup_for_session_id']);

        if ($completed->isEmpty()) {
            return ['rows' => [], 'totals' => $this->totals(collect())];
        }

        $teacherAmounts = $this->payouts->sessionPayoutAmounts($completed);
        $teachers = Teacher::whereIn('id', $completed->pluck('teacher_id')->unique())->with('user:id,name')->get()->keyBy('id');

        // تسويات تسجيل/انضمام متأخر مستحقة لجلسات مصروفة ولم تُولَّد بعد — تدخل أول كشف قادم
        $pendingSettlements = [];
        foreach ($teachers as $teacher) {
            $pendingSettlements += $this->payouts->lateJoinSettlements($teacher);
        }

        $settlementItems = PayoutItem::whereIn('class_session_id', $completed->pluck('id'))
            ->where('type', PayoutItem::TYPE_SETTLEMENT)
            ->selectRaw('class_session_id, SUM(amount) as total')
            ->groupBy('class_session_id')
            ->pluck('total', 'class_session_id');

        $platformSessions = $completed->filter(fn (ClassSession $s) => ($teacherAmounts[$s->id]['amount'] ?? 0) > 0
            && $this->consumesSlot($s));

        $platformAmounts = $this->platformSessionAmounts(
            $platformSessions,
            $this->payouts->bookingSharesBeyondCap($platformSessions->filter(fn (ClassSession $s) => $s->course_id === null)),
        );

        // ما صُرف للمعلم زيادةً على قيمة عقده (تعويض غياب الطلاب ثم تعويضيتها) يتحمّله هامش المنصة
        $compensation = $this->payouts->compensationBeyondContract($completed);
        // تعويض غياب الطلاب الثابت المصروف فعلاً — سالب على تعويضية خُصم منها ما صُرف لأصليتها
        $fixedCompensations = $this->payouts->compensations($completed);
        // قيمة الجلسة الفائتة بلا تعويضية تبقى للمنصة — تنتقل للمعلم إن أُنشئت تعويضية
        $forfeited = $this->forfeitedSessionAmounts($completed);

        $rows = $completed->groupBy('teacher_id')->map(function (Collection $group, int $tId) use ($teacherAmounts, $pendingSettlements, $settlementItems, $platformAmounts, $compensation, $fixedCompensations, $forfeited, $teachers) {
            $row = [
                'teacherId' => $tId,
                'teacherName' => $teachers->get($tId)?->user?->name,
                'sessionsCount' => 0,
                'studentNoShowCount' => 0,
                'teacherAbsentCount' => 0,
                'unpaidCount' => 0,
                'teacherRevenue' => 0.0,
                'inPayoutsAmount' => 0.0,
                'notInPayoutsAmount' => 0.0,
                'settlementsAmount' => 0.0,
                'platformRevenue' => 0.0,
            ];

            foreach ($group as $session) {
                $amount = $teacherAmounts[$session->id] ?? ['amount' => 0.0, 'paid' => false];
                $pending = $pendingSettlements[$session->id] ?? 0.0;

                if ($session->attendance_outcome === ClassSession::OUTCOME_TEACHER_ABSENT) {
                    $row['teacherAbsentCount']++;
                }

                // قبل فحص المستحق: غياب المعلم لا مستحق له لكن قيمته للمنصة
                $row['platformRevenue'] += $forfeited[$session->id] ?? 0.0;

                // بلا مستحق: غياب المعلم، أو بلا اشتراك مدفوع، أو خارج السقف — لا تُعد حصة
                if ($amount['amount'] <= 0 && $pending <= 0) {
                    continue;
                }

                $row['sessionsCount']++;

                if ($session->attendance_outcome === ClassSession::OUTCOME_STUDENT_NO_SHOW) {
                    $row['studentNoShowCount']++;
                }

                if ($amount['paid']) {
                    $row['inPayoutsAmount'] += $amount['amount'];
                } else {
                    $row['unpaidCount']++;
                    $row['notInPayoutsAmount'] += $amount['amount'];
                }

                $row['notInPayoutsAmount'] += $pending;
                $row['settlementsAmount'] += (float) ($settlementItems[$session->id] ?? 0) + $pending;
                $row['teacherRevenue'] += $amount['amount'] + $pending;
                $row['platformRevenue'] += ($platformAmounts[$session->id] ?? 0.0)
                    - ($compensation[$session->id] ?? 0.0)
                    - ($fixedCompensations[$session->id] ?? 0.0);
            }

            foreach (['teacherRevenue', 'inPayoutsAmount', 'notInPayoutsAmount', 'settlementsAmount', 'platformRevenue'] as $key) {
                $row[$key] = round($row[$key], 2);
            }

            return $row;
        })
            ->filter(fn (array $row) => $row['sessionsCount'] > 0 || $row['teacherAbsentCount'] > 0 || $row['platformRevenue'] != 0.0)
            ->sortByDesc('platformRevenue')
            ->values();

        return ['rows' => $rows->all(), 'totals' => $this->totals($rows)];
    }

    private function totals(Collection $rows): array
    {
        $totals = [];

        foreach (['sessionsCount', 'studentNoShowCount', 'teacherAbsentCount', 'unpaidCount'] as $key) {
            $totals[$key] = (int) $rows->sum($key);
        }

        foreach (['teacherRevenue', 'inPayoutsAmount', 'notInPayoutsAmount', 'settlementsAmount', 'platformRevenue'] as $key) {
            $totals[$key] = round((float) $rows->sum($key), 2);
        }

        return $totals;
    }

    /**
     * حصة المنصة من كل جلسة — نظير PayoutService::courseSessionValues/bookingShares لكن من
     * platform_amount: دورة → حصة المنصة من كل تسجيلاتها المدفوعة ÷ total_sessions، باقة →
     * مجموع (platform_amount ÷ sessions_total) لكل حجز مدفوع يُحتسب في الجلسة، عدا الحجوزات
     * التي سقطت حصتها بسقف الحجز ($beyondCap) — كما سقطت من مستحق المعلم، فلا يتجاوز عائد
     * المنصة من حجز ما دُفع لها منه.
     *
     * @param  array<int, array<int, true>>  $beyondCap  [session_id => [booking_id => true]]
     * @return array<int, float> [class_session_id => platform]
     */
    private function platformSessionAmounts(Collection $sessions, array $beyondCap): array
    {
        $amounts = [];

        $courseSessions = $sessions->filter(fn (ClassSession $s) => $s->course_id !== null);

        if ($courseSessions->isNotEmpty()) {
            $platformByCourseId = Enrollment::whereIn('course_id', $courseSessions->pluck('course_id')->unique())
                ->whereIn('status', ClassSession::PAID_ENROLLMENT_STATUSES)
                ->selectRaw('course_id, SUM(platform_amount) as total')
                ->groupBy('course_id')
                ->pluck('total', 'course_id');

            foreach ($courseSessions as $session) {
                $divisor = $session->course?->total_sessions ?? 0;

                $amounts[$session->id] = $divisor > 0
                    ? ((float) ($platformByCourseId[$session->course_id] ?? 0)) / $divisor
                    : 0.0;
            }
        }

        $bookingSessions = $sessions->filter(fn (ClassSession $s) => $s->course_id === null);

        if ($bookingSessions->isNotEmpty()) {
            $bookingIdsBySession = $this->payableBookingIds($bookingSessions);
            $bookingsById = $this->paidBookings($bookingIdsBySession);

            foreach ($bookingSessions as $session) {
                $bookingIds = $bookingIdsBySession->get($session->id);
                $excluded = $beyondCap[$session->id] ?? [];

                $amounts[$session->id] = $bookingIds->sum(function ($bookingId) use ($bookingsById, $excluded) {
                    if (isset($excluded[$bookingId])) {
                        return 0.0;
                    }

                    $booking = $bookingsById->get($bookingId);

                    return $booking ? ((float) $booking->platform_amount) / $booking->sessions_total : 0.0;
                });
            }
        }

        return $amounts;
    }

    /**
     * قيمة كل جلسة فائتة بلا تعويضية تبقى للمنصة: ما لم يُصرف للمعلم من قيمتها (كاملة لغياب المعلم
     * وللتعويض الثابت، والباقي بعد النسبة لغياب الطلاب المحسوم بنسبة قبله) + حصة المنصة. التعويض
     * الثابت لا يُطرح هنا — يطرحه getReport من نفس الجلسة. ضمن السقف: الجلسة الفائتة تأخذ مكاناً
     * لم تملأه جلسة منعقدة، فلا يتجاوز ما يُنسب للمنصة من حجز/دورة ما دُفع عنه (جلسات زائدة في
     * بيانات قديمة لا قيمة لها). الأماكن تُحسب على كل جلسات الحجز/الدورة المكتملة لا المعطاة
     * وحدها، وتُوزَّع على الفائتة بترتيب الموعد — فالنتيجة لا تتغيّر بنطاق التقرير. تعويضية قائمة
     * (ولو لم تُعقد بعد) تُسقط القيمة عن أصليتها: تنتقل للمعلم عن التعويضية.
     *
     * @return array<int, float> [class_session_id => amount]
     */
    private function forfeitedSessionAmounts(Collection $sessions): array
    {
        $missed = $sessions->filter(fn (ClassSession $s) => $this->isMissed($s));

        if ($missed->isEmpty()) {
            return [];
        }

        $courseIds = $missed->pluck('course_id')->filter()->unique()->values();
        $bookingIds = $this->payableBookingIds($missed->filter(fn (ClassSession $s) => $s->course_id === null))
            ->flatten()
            ->unique()
            ->values();

        $related = ClassSession::query()
            ->where('status', 'completed')
            ->where(fn ($q) => $q
                ->whereIn('course_id', $courseIds)
                ->orWhere(fn ($b) => $b
                    ->whereNull('course_id')
                    ->where(fn ($r) => $r
                        ->whereIn('booking_id', $bookingIds)
                        ->orWhereHas('attendees', fn ($a) => $a->whereIn('booking_id', $bookingIds)))))
            ->with('course:id,total_sessions')
            ->get(['id', 'course_id', 'booking_id', 'scheduled_at', 'attendance_outcome', 'teacher_payout_percent']);

        $hasMakeup = ClassSession::whereIn('makeup_for_session_id', $related->filter(fn (ClassSession $s) => $this->isMissed($s))->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->pluck('makeup_for_session_id')
            ->flip();

        $forfeitable = $related
            ->filter(fn (ClassSession $s) => $this->isMissed($s) && ! $hasMakeup->has($s->id))
            ->sortBy([['scheduled_at', 'asc'], ['id', 'asc']]);

        return $this->forfeitedCourseAmounts($related, $forfeitable)
            + $this->forfeitedBookingAmounts($related, $forfeitable);
    }

    /** @return array<int, float> */
    private function forfeitedCourseAmounts(Collection $related, Collection $forfeitable): array
    {
        $courseForfeitable = $forfeitable->filter(fn (ClassSession $s) => $s->course_id !== null);

        if ($courseForfeitable->isEmpty()) {
            return [];
        }

        $totals = Enrollment::whereIn('course_id', $courseForfeitable->pluck('course_id')->unique())
            ->whereIn('status', ClassSession::PAID_ENROLLMENT_STATUSES)
            ->selectRaw('course_id, SUM(provider_amount) as provider, SUM(platform_amount) as platform')
            ->groupBy('course_id')
            ->get()
            ->keyBy('course_id');

        $held = $related
            ->filter(fn (ClassSession $s) => $s->course_id !== null && $this->consumesSlot($s))
            ->countBy('course_id');

        $available = [];
        $amounts = [];

        foreach ($courseForfeitable as $session) {
            $courseId = $session->course_id;
            $divisor = $session->course?->total_sessions ?? 0;
            $available[$courseId] ??= max(0, $divisor - ($held[$courseId] ?? 0));

            if ($available[$courseId] <= 0) {
                continue;
            }

            $available[$courseId]--;
            $total = $totals->get($courseId);

            $amounts[$session->id] = ((float) ($total?->provider ?? 0) * $this->unpaidRatio($session)
                + (float) ($total?->platform ?? 0)) / $divisor;
        }

        return $amounts;
    }

    /** نظير الدورة لكل حجز باقة — السقف على حصة الحجز لا الجلسة، كـ PayoutService */
    private function forfeitedBookingAmounts(Collection $related, Collection $forfeitable): array
    {
        $bookingForfeitable = $forfeitable->filter(fn (ClassSession $s) => $s->course_id === null);

        if ($bookingForfeitable->isEmpty()) {
            return [];
        }

        $bookingIdsBySession = $this->payableBookingIds($related->filter(fn (ClassSession $s) => $s->course_id === null));
        $bookingsById = $this->paidBookings($bookingIdsBySession);

        $held = [];

        foreach ($related as $session) {
            if ($session->course_id === null && $this->consumesSlot($session)) {
                foreach ($bookingIdsBySession->get($session->id) as $bookingId) {
                    $held[$bookingId] = ($held[$bookingId] ?? 0) + 1;
                }
            }
        }

        $available = [];
        $amounts = [];

        foreach ($bookingForfeitable as $session) {
            foreach ($bookingIdsBySession->get($session->id) as $bookingId) {
                $booking = $bookingsById->get($bookingId);

                if (! $booking) {
                    continue;
                }

                $available[$bookingId] ??= max(0, $booking->sessions_total - ($held[$bookingId] ?? 0));

                if ($available[$bookingId] <= 0) {
                    continue;
                }

                $available[$bookingId]--;

                $amounts[$session->id] = ($amounts[$session->id] ?? 0.0)
                    + ((float) $booking->teacher_amount * $this->unpaidRatio($session) + (float) $booking->platform_amount)
                    / $booking->sessions_total;
            }
        }

        return $amounts;
    }

    /**
     * حجوزات كل جلسة باقة المحتسبة (ClassSession::payableBookingIdsBySession)، مع احتياط الجلسات
     * القديمة بلا أسطر حضور: حجزها المالك.
     *
     * @return Collection<int, Collection<int, int>> [class_session_id => booking ids]
     */
    private function payableBookingIds(Collection $bookingSessions): Collection
    {
        $bySession = ClassSession::payableBookingIdsBySession($bookingSessions);

        return $bookingSessions->mapWithKeys(fn (ClassSession $session) => [
            $session->id => $bySession->has($session->id)
                ? $bySession->get($session->id)
                : collect([$session->booking_id])->filter(),
        ]);
    }

    private function paidBookings(Collection $bookingIdsBySession): Collection
    {
        return Booking::whereIn('id', $bookingIdsBySession->flatten()->unique())
            ->whereIn('status', ClassSession::PAID_BOOKING_STATUSES)
            ->where('sessions_total', '>', 0)
            ->get(['id', 'teacher_amount', 'platform_amount', 'sessions_total'])
            ->keyBy('id');
    }

    private function isMissed(ClassSession $session): bool
    {
        return in_array($session->attendance_outcome, self::MISSED_OUTCOMES, true);
    }

    private function consumesSlot(ClassSession $session): bool
    {
        return $session->attendance_outcome === null
            || in_array($session->attendance_outcome, self::PLATFORM_SLOT_OUTCOMES, true);
    }

    /** ما لم يُصرف للمعلم من قيمة الجلسة بنسبتها المثبَّتة (NULL = كاملة كـ PayoutService) */
    private function unpaidRatio(ClassSession $session): float
    {
        $percent = $session->teacher_payout_percent === null ? 100.0 : (float) $session->teacher_payout_percent;

        return 1 - $percent / 100;
    }
}
