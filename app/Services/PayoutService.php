<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\SessionAttendee;
use App\Models\Teacher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayoutService
{
    /**
     * نتائج الحضور التي تستهلك مكاناً من السقف — total_sessions للدورة، وsessions_total لكل
     * حجز باقة: جلسة عُقدت فعلاً، أو بلا دليل غياب (untracked/NULL قبل الحضور التلقائي).
     * student_no_show يُصرف تعويضها الثابت (أو نسبتها قبله) خارج السقف، وteacher_absent/no_paid_attendee لا تُصرف
     * ولا تستهلك مكاناً.
     */
    private const SLOT_OUTCOMES = [ClassSession::OUTCOME_ATTENDED, ClassSession::OUTCOME_UNTRACKED];

    /**
     * زر توليد المستحقات لدى الأدمن (POST payouts/generate) — الطريق الوحيد لإنشاء كشوف المستحقات:
     * كشف لكل معلم بكل جلساته المكتملة غير المصروفة حتى لحظة الضغط، أياً كان تاريخها، وتسوياته
     * المستحقة. معلم بلا مستحق موجب يُتخطى بلا كشف. آمن التكرار: ضغطة ثانية لا تجد إلا ما اكتمل
     * بعد الأولى. معاملة وقفل مستقلان لكل معلم — ضغطتان متزامنتان لا تصرفان نفس الجلسة مرتين.
     *
     * @return int عدد الكشوف المُنشأة
     */
    public function generateAll(?Carbon $until = null): int
    {
        $until ??= now();

        $teacherIds = ClassSession::query()
            ->where('status', 'completed')
            ->where('scheduled_at', '<=', $until)
            ->distinct()
            ->pluck('teacher_id');

        $created = 0;

        foreach (Teacher::whereIn('id', $teacherIds)->get() as $teacher) {
            try {
                $this->generateForPeriod($teacher, null, $until);
                $created++;
            } catch (ValidationException) {
                // لا مستحق موجب لهذا المعلم
            }
        }

        return $created;
    }

    /**
     * معاملة واحدة: فشل في المنتصف لا يترك كشفاً بلا بنود أو بنوداً بلا مجموع. وقفل صف
     * المعلم أول استعلام فيها: توليدان متزامنان لنفس المعلم يُنفَّذان بالتتابع، فيقرأ الثاني
     * الجلسات والتسويات بعد حفظ الأول ولا يصرفها مرتين. لو سبقت قراءةٌ القفلَ لبقيت لقطتها
     * القديمة بعد الانتظار (REPEATABLE READ). $periodStart = null: كل غير المصروف حتى $periodEnd
     * (زر التوليد)، وبداية الكشف أقدم جلسة فيه.
     */
    public function generateForPeriod(Teacher $teacher, ?Carbon $periodStart, Carbon $periodEnd): Payout
    {
        return DB::transaction(function () use ($teacher, $periodStart, $periodEnd) {
            Teacher::whereKey($teacher->id)->lockForUpdate()->first();

            return $this->createPayout($teacher, $periodStart, $periodEnd);
        });
    }

    /**
     * لا كشف بلا بند موجب: جلسات غاب عنها المعلم، أو بلا اشتراك مدفوع، أو خارج السقف وحدها كانت
     * تُنشئ كشفاً بصفر بلا بنود — يبقى قابلاً للاعتماد والتحويل، وتعود جلساته في كل توليد تالٍ
     * لأنها لم تُصرف، فيتكرر الكشف الفارغ كل شهر.
     */
    private function createPayout(Teacher $teacher, ?Carbon $periodStart, Carbon $periodEnd): Payout
    {
        $alreadyPaidSessionIds = PayoutItem::whereHas('payout', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->pluck('class_session_id');

        $sessions = ClassSession::where('teacher_id', $teacher->id)
            ->where('status', 'completed')
            ->when(
                $periodStart === null,
                fn ($q) => $q->where('scheduled_at', '<=', $periodEnd),
                fn ($q) => $q->whereBetween('scheduled_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()]),
            )
            ->whereNotIn('id', $alreadyPaidSessionIds)
            ->with('course')
            ->get();

        $amounts = $this->payableAmounts($sessions, afterPreviousPayouts: true);

        $gross = 0.0;
        $count = 0;
        $items = [];

        foreach ($sessions as $session) {
            // صفر: غياب المعلم، أو بلا اشتراك مدفوع، أو كلها خارج السقف — لا بند لها
            $amount = $amounts[$session->id]['amount'] ?? 0.0;

            if ($amount <= 0) {
                continue;
            }

            $items[] = ['class_session_id' => $session->id, 'type' => PayoutItem::TYPE_SESSION, 'amount' => $amount];
            $gross += $amount;
            $count++;
        }

        // تسويات التسجيل/الانضمام المتأخر تُضاف لأول دفعة تالية بصرف النظر عن فترتها — جلساتها صُرفت سابقاً
        foreach ($this->lateJoinSettlements($teacher) as $sessionId => $amount) {
            $items[] = ['class_session_id' => $sessionId, 'type' => PayoutItem::TYPE_SETTLEMENT, 'amount' => $amount];
            $gross += $amount;
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'period' => ['لا توجد مستحقات غير مدفوعة لهذا المعلم ضمن هذه الفترة'],
            ]);
        }

        // زر التوليد: من أقدم جلسة في الكشف (جلسة أو تسوية) — قد تسبق آخر كشف إن فاتته
        $periodStart ??= ClassSession::whereIn('id', array_column($items, 'class_session_id'))->min('scheduled_at');

        $payout = Payout::create([
            'teacher_id' => $teacher->id,
            'period_start' => Carbon::parse($periodStart)->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'gross_amount' => round($gross, 2),
            'net_amount' => round($gross, 2),
            'sessions_count' => $count,
            'status' => 'pending',
        ]);

        $now = now();

        PayoutItem::insert(array_map(fn (array $item) => $item + [
            'payout_id' => $payout->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $items));

        return $payout->fresh(['items']);
    }

    /**
     * للكشف حالتان فقط: غير مدفوع (pending) عند التوليد، ومدفوع (paid) بهذا الزر — مرجع التحويل
     * اختياري. لا خطوة اعتماد وسيطة. التحويل مرة واحدة: كشف مدفوع لا يُعاد تحويله.
     */
    public function markPaid(Payout $payout, ?string $transferReference = null): Payout
    {
        if ($payout->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['هذا الكشف مدفوع بالفعل'],
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
     * ثابتة بغض النظر عن عدد الجلسات. عامة كي يعرض تقرير حصص المعلمين التسويات المستحقة
     * التي لم تُولَّد في كشف بعد (TeacherSessionsReportService).
     *
     * @return array<int, float> [class_session_id => amount]
     */
    public function lateJoinSettlements(Teacher $teacher): array
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
     * حصص الحجوزات التي سقطت بسقف الحجز في جلسات الباقة المعطاة — كي يستبعدها عائد المنصة
     * في تقرير حصص المعلمين كما سقطت من مستحق المعلم. المصروفة: السقف من الصفر على كل
     * جلسات حجوزاتها المصروفة (كتسويات lateJoinSettlements)، والمكتملة غير المصروفة: بعد
     * ما استُهلك في دفعات سابقة (كـ sessionPayoutAmounts). الأماكن تُوزَّع على كل جلسات
     * الحجز لا على المعطاة وحدها، فجلسة خارج نطاق التقرير تستهلك مكانها.
     *
     * @return array<int, array<int, true>> [session_id => [booking_id => true]]
     */
    public function bookingSharesBeyondCap(Collection $sessions): array
    {
        $bookingIds = array_keys($this->bookingShares($sessions)['limits']);

        if ($bookingIds === []) {
            return [];
        }

        $related = ClassSession::query()
            ->where('status', 'completed')
            ->whereNull('course_id')
            ->where(fn ($q) => $q
                ->whereIn('booking_id', $bookingIds)
                ->orWhereHas('attendees', fn ($a) => $a->whereIn('booking_id', $bookingIds)))
            ->get(['id', 'booking_id', 'course_id', 'scheduled_at', 'attendance_outcome']);

        $paidIds = PayoutItem::whereIn('class_session_id', $related->pluck('id'))->pluck('class_session_id')->flip();
        [$paid, $pending] = $related->partition(fn (ClassSession $s) => $paidIds->has($s->id));

        $paidShares = $this->bookingShares($paid);
        $pendingShares = $this->bookingShares($pending);

        return $this->sharesBeyondBookingCap($paid, $paidShares['shares'], $paidShares['limits'], [])
            + $this->sharesBeyondBookingCap(
                $pending,
                $pendingShares['shares'],
                $pendingShares['limits'],
                $this->usedBookingSlots(array_keys($pendingShares['limits'])),
            );
    }

    /**
     * ما يُصرف للمعلم من حجز/دورة زيادةً على قيمة عقده (teacher_amount للحجز، مستحق المركز من
     * كل تسجيلاتها المدفوعة للدورة) — يتحمّله هامش المنصة، فيُخصم من عائدها في تقرير حصص
     * المعلمين. ينشأ من جلسات student_no_show المحسومة بنسبة قبل التعويض الثابت: تُصرف بنسبتها
     * خارج السقف، ثم تملأ تعويضيتها مكانها كاملة (حجز 400: ثلاث جلسات + 50 عن غياب الطالب +
     * تعويضية = 450). التعويض الثابت خارج العقد أصلاً — يخصمه التقرير كاملاً (compensations)
     * ولا يدخل هنا. الزيادة تُنسب للجلسة
     * التي تجاوز عندها المصروف المتراكم قيمة العقد بترتيب الموعد، على كل جلسات الحجز/الدورة
     * المكتملة لا المعطاة وحدها — المصروفة بالسقف من الصفر (كتسويات lateJoinSettlements)،
     * والمكتملة غير المصروفة بعد ما استُهلك في دفعات سابقة (كـ sessionPayoutAmounts).
     *
     * @return array<int, float> [class_session_id => amount]
     */
    public function compensationBeyondContract(Collection $sessions): array
    {
        $courseIds = $sessions->pluck('course_id')->filter()->unique()->values();
        $bookingIds = array_keys($this->bookingShares($sessions)['limits']);

        if ($courseIds->isEmpty() && $bookingIds === []) {
            return [];
        }

        $related = ClassSession::query()
            ->where('status', 'completed')
            ->where(fn ($q) => $q
                ->whereIn('course_id', $courseIds)
                ->orWhere(fn ($b) => $b
                    ->whereNull('course_id')
                    ->where(fn ($r) => $r
                        ->whereIn('booking_id', $bookingIds)
                        ->orWhereHas('attendees', fn ($a) => $a->whereIn('booking_id', $bookingIds)))))
            ->with('course')
            ->get();

        $paidIds = PayoutItem::whereIn('class_session_id', $related->pluck('id'))->pluck('class_session_id')->flip();
        [$paid, $pending] = $related->partition(fn (ClassSession $s) => $paidIds->has($s->id));

        $paidContributions = $this->contractContributions($paid, afterPreviousPayouts: false);
        $pendingContributions = $this->contractContributions($pending, afterPreviousPayouts: true);
        $amounts = $paidContributions['amounts'] + $pendingContributions['amounts'];
        $values = $paidContributions['values'] + $pendingContributions['values'];

        $spent = [];
        $beyond = [];

        foreach ($related->sortBy([['scheduled_at', 'asc'], ['id', 'asc']]) as $session) {
            foreach ($amounts[$session->id] ?? [] as $contract => $amount) {
                $before = $spent[$contract] ?? 0.0;
                $spent[$contract] = $before + $amount;
                $excess = max(0, $spent[$contract] - $values[$contract]) - max(0, $before - $values[$contract]);

                if ($excess > 0) {
                    $beyond[$session->id] = ($beyond[$session->id] ?? 0.0) + $excess;
                }
            }
        }

        return array_map(fn (float $amount) => round($amount, 2), $beyond);
    }

    /**
     * نصيب كل عقد (دورة أو حجز باقة) من مستحق كل جلسة بعد النسبة والسقفين — نفس payableAmounts
     * لكن مفصّلاً لكل عقد مع قيمته الكلية، كي تُقارن compensationBeyondContract المصروف منه بها.
     *
     * @return array{amounts: array<int, array<string, float>>, values: array<string, float>}
     *         amounts: [session_id => ['course:id'|'booking:id' => amount]]، values: [contract => قيمة العقد]
     */
    private function contractContributions(Collection $sessions, bool $afterPreviousPayouts): array
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

        $amounts = [];
        $values = [];

        foreach ($sessions as $session) {
            $percent = $this->payablePercent($session) / 100;

            if ($session->course_id !== null) {
                $contract = 'course:'.$session->course_id;
                $value = $courseValues[$session->id] ?? 0.0;
                $values[$contract] = $value * ($session->course?->total_sessions ?? 0);

                if (! isset($beyondCourse[$session->id])) {
                    $amounts[$session->id][$contract] = $value * $percent;
                }

                continue;
            }

            foreach (array_diff_key($shares[$session->id] ?? [], $beyondBooking[$session->id] ?? []) as $bookingId => $share) {
                $amounts[$session->id]['booking:'.$bookingId] = $share * $percent;
            }

            foreach ($shares[$session->id] ?? [] as $bookingId => $share) {
                $values['booking:'.$bookingId] = $share * $limits[$bookingId];
            }
        }

        return ['amounts' => $amounts, 'values' => $values];
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
     * تعويض غياب الطلاب الثابت لكل جلسة — مثبَّت عند انتهائها (teacher_compensation_amount)، مرة
     * واحدة للجلسة أياً كان عدد حجوزاتها، خارج السقف وخارج قيمة العقد (يتحمّله هامش المنصة).
     * جلسات غياب الطلاب المحسومة قبله بنسبة من مستحقها (payablePercent) لا تعويض ثابت لها.
     *
     * جلسة لها تعويضية (غير ملغاة) لا يُصرف تعويضها: الطلاب يأخذون الجلسة كاملة في التعويضية
     * والمعلم يُصرف له عنها. فإن كان صُرف في كشف قبل إنشاء التعويضية — والصرف لا يُسترد — يبقى
     * على الأصلية ويُخصم من مستحق التعويضية (قيمة سالبة عليها)، فالنتيجة واحدة أياً كان التوقيت.
     *
     * الجلسة التعويضية لا تعويض لها (completeEndedSessions)، فالتعويض الوحيد في سلسلة التعويض
     * (أصلية ← تعويضية ← تعويضيتها...) على أصليتها الأولى، ويُخصم المصروف منه من كل تعويضية في
     * السلسلة: الوسيطة فائتة بلا مستحق فلا يؤثر فيها (لا مستحق سالب)، والتي عُقدت — ولا تعويضية
     * لها بعدها — يُخصم منها. كان يُخصم من الأصلية المباشرة وحدها، فتعويضية ثانية بعد تعويضية
     * فائتة تُصرف كاملة فوق التعويض المصروف. استعلام إضافي لكل مستوى في السلسلة.
     *
     * @return array<int, float> [class_session_id => amount] موجب على الأصلية، سالب على تعويضيتها
     */
    public function compensations(Collection $sessions): array
    {
        $own = $sessions
            ->filter(fn (ClassSession $s) => (float) ($s->teacher_compensation_amount ?? 0) > 0)
            ->mapWithKeys(fn (ClassSession $s) => [$s->id => (float) $s->teacher_compensation_amount]);

        $rootOf = $this->makeupRoots($sessions->filter(fn (ClassSession $s) => $s->makeup_for_session_id !== null));

        if ($own->isEmpty() && $rootOf === []) {
            return [];
        }

        // تعويض الأصليات الأولى لسلاسل التعويضيات في هذه الجلسات — المصروف منه فقط يُخصم من التعويضية
        $originalCompensation = $rootOf === [] ? collect() : ClassSession::whereIn('id', array_unique($rootOf))
            ->where('teacher_compensation_amount', '>', 0)
            ->pluck('teacher_compensation_amount', 'id');

        $hasMakeup = $own->isEmpty() ? collect() : ClassSession::whereIn('makeup_for_session_id', $own->keys())
            ->where('status', '!=', 'cancelled')
            ->pluck('makeup_for_session_id')
            ->flip();

        $paid = PayoutItem::whereIn('class_session_id', $own->keys()->merge($originalCompensation->keys())->unique())
            ->where('type', PayoutItem::TYPE_SESSION)
            ->pluck('class_session_id')
            ->flip();

        $result = [];

        foreach ($sessions as $session) {
            $amount = 0.0;

            if ($own->has($session->id) && (! $hasMakeup->has($session->id) || $paid->has($session->id))) {
                $amount += $own[$session->id];
            }

            $originalId = $rootOf[$session->id] ?? null;

            if ($originalId !== null && $originalCompensation->has($originalId) && $paid->has($originalId)) {
                $amount -= (float) $originalCompensation[$originalId];
            }

            if ($amount != 0.0) {
                $result[$session->id] = $amount;
            }
        }

        return $result;
    }

    /**
     * الأصلية الأولى (غير التعويضية) لكل تعويضية — صعوداً عبر makeup_for_session_id.
     *
     * @return array<int, int> [makeup_session_id => root_session_id]
     */
    private function makeupRoots(Collection $makeups): array
    {
        $rootOf = $makeups->mapWithKeys(fn (ClassSession $s) => [$s->id => $s->makeup_for_session_id])->all();
        $parentOf = [];
        $checked = [];
        $toCheck = array_values(array_unique($rootOf));

        while ($toCheck !== []) {
            $checked += array_flip($toCheck);
            $found = ClassSession::whereIn('id', $toCheck)
                ->whereNotNull('makeup_for_session_id')
                ->pluck('makeup_for_session_id', 'id')
                ->all();
            $parentOf += $found;
            $toCheck = array_values(array_diff(array_unique($found), array_keys($checked)));
        }

        foreach ($rootOf as $makeupId => $rootId) {
            while (isset($parentOf[$rootId])) {
                $rootId = $parentOf[$rootId];
            }

            $rootOf[$makeupId] = $rootId;
        }

        return $rootOf;
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
        $compensations = $this->compensations($sessions);

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
                // الخصم من التعويضية لا يتجاوز مستحقها — لا مستحق سالب
                'amount' => max(0.0, round($value * $this->payablePercent($session) / 100 + ($compensations[$session->id] ?? 0.0), 2)),
                'capped' => $capped,
            ];
        }

        return $result;
    }

    /**
     * قيمة كل جلسة للمعلم قبل نسبة الحضور والسقفين: حصتها من مستحق الدورة، أو مجموع حصص
     * حجوزاتها المدفوعة. أساس تعويض غياب الطلاب النسبي (SessionService::completeEndedSessions).
     *
     * @return array<int, float> [class_session_id => value]
     */
    public function sessionValues(Collection $sessions): array
    {
        $values = $this->courseSessionValues($sessions);

        foreach ($this->bookingShares($sessions)['shares'] as $sessionId => $shares) {
            $values[$sessionId] = array_sum($shares);
        }

        return $values;
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
