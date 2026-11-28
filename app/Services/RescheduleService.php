<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Notifications\RescheduleRequestEscalated;
use App\Notifications\RescheduleRequestReviewed;
use App\Notifications\RescheduleRequestSubmitted;
use App\Notifications\RescheduleResponseRequested;
use App\Notifications\SessionRescheduledByAdmin;
use App\Traits\LogsAuditEvents;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * مساران لطلب تغيير الموعد (الجلسات الفردية فقط):
 *
 *  - تلقائي (الافتراضي، reschedule_requires_admin = false): الطلب بموعد مقترح يذهب أولاً
 *    للطرف الآخر (counterparty_response = pending). موافقته تطبّق الموعد فوراً بلا أدمن؛
 *    رفضه أو انقضاء مهلته (EscalateRescheduleRequestsJob) يصعّد الطلب للأدمن (escalated).
 *  - يدوي: تبليغ الغياب بلا موعد مقترح، أو طلب لم يبقَ قبل جلسته وقت يكفي لرد الطرف
 *    الآخر، أو reschedule_requires_admin = true — يذهب للأدمن مباشرة كما كان سابقاً.
 *
 * الطلب يبقى status = pending طوال المرحلتين؛ الأدمن يستطيع حسمه في أي لحظة منهما.
 * الفرق بين ما قبل/بعد 24 ساعة هو فقط within_free_window (يحدد الأولوية) وإلزامية السبب.
 */
class RescheduleService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly ScheduleConflictService $conflicts,
        private readonly SessionService $sessions,
    ) {}

    /**
     * طلب موعد جلسة تعويضية لجلسة فائتة غاب عنها المعلم (السياسة: تعويض مجاني لغياب المعلم
     * وحده). يمرّ بنفس مسار تغيير الموعد: الطرف الآخر يوافق فتُنشأ التعويضية فوراً، أو يرفض/لا
     * يرد فيُحال للأدمن. غياب الطالب والجلسات الجماعية تبقى للأدمن (scheduleMakeupByAdmin).
     */
    public function requestMakeup(ClassSession $session, User $requester, string $requesterRole, Carbon $proposedAt, ?string $reason = null): RescheduleRequest
    {
        if (! SessionService::isEligibleForMakeupRequest($session)) {
            throw ValidationException::withMessages([
                'session' => ['طلب الجلسة التعويضية متاح فقط لجلسة فردية منتهية غاب عنها المعلم ولم تُنشأ لها تعويضية'],
            ]);
        }

        if ($proposedAt->lte(now())) {
            throw ValidationException::withMessages([
                'proposed_scheduled_at' => ['يجب أن يكون موعد الجلسة التعويضية في المستقبل'],
            ]);
        }

        $this->assertProposedTimeFarEnough($proposedAt, 'موعد الجلسة التعويضية');

        $this->assertSessionIsPaid($session, $requester, $requesterRole);

        if (RescheduleRequest::where('class_session_id', $session->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'session' => ['يوجد طلب موعد تعويضي قيد المراجعة بالفعل لهذه الجلسة'],
            ]);
        }

        // الجلسة نفسها فائتة — الفحص للمعلم والطالب في الموعد المقترح دون استثناء أي جلسة
        $student = $session->loadMissing('booking.student')->booking?->student;
        $this->conflicts->assertNoConflict($student, $session->teacher_id, [$proposedAt], $session->duration_min);

        $counterpartyDeadline = $this->counterpartyDeadlineFor($proposedAt);

        $rescheduleRequest = RescheduleRequest::create([
            'class_session_id' => $session->id,
            'booking_id' => $session->booking_id,
            'type' => RescheduleRequest::TYPE_MAKEUP,
            'requested_by' => $requester->id,
            'requester_role' => $requesterRole,
            'current_scheduled_at' => $session->scheduled_at,
            'proposed_scheduled_at' => $proposedAt,
            'within_free_window' => true,
            'reason' => $reason,
            'status' => 'pending',
            'counterparty_response' => $counterpartyDeadline ? 'pending' : null,
            'counterparty_deadline' => $counterpartyDeadline,
            'sla_due_at' => $counterpartyDeadline ? null : $this->adminSlaDueAt(),
        ]);

        if ($counterpartyDeadline) {
            $this->notifyCounterpartyRequested($session, $rescheduleRequest, $requester);
        } else {
            $this->notifyRequestSubmitted($session, $rescheduleRequest, $requesterRole);
        }

        return $rescheduleRequest;
    }

    /**
     * لم يكن أي تغيير موعد (طلب أو موافقة) يتحقق من تعارض الموعد الجديد إطلاقاً
     * — يمكن نقل جلسة لتتطابق تماماً مع جلسة أخرى لنفس الطالب/المعلم (حتى لو
     * كانت الجلسة الأخرى من نفس الباقة تحديداً) بلا أي رفض. يوازي فحص
     * ScheduleConflictService المستخدَم في كل مسارات إنشاء الحجز — الجلسة نفسها
     * تُستبعَد من فحصها (excludeSessionIds) فلا "تتعارض مع نفسها".
     *
     * الطالب المرجعي هو طالب الحجز نفسه (booking.student) — نفس نمط
     * resolvePaymentStatus() أعلاه — لا حضور الجلسة (attendees)؛ تغيير الموعد
     * أصلاً مقصور على باقات فردية فقط (request() يرفض الجماعية صراحة)، فحجز
     * الجلسة له طالب واحد محدَّد دوماً. assertNoConflict() تتحقق من جانب
     * المعلم أيضاً ضمن استعلامها الداخلي بصرف النظر عن الطالب المُمرَّر، فتُغطي
     * الحالتين معاً بنداء واحد.
     */
    private function assertNewTimeHasNoConflict(ClassSession $session, Carbon $newTime): void
    {
        $student = $session->loadMissing('booking.student')->booking?->student;

        if (! $student) {
            return;
        }

        $this->conflicts->assertNoConflict($student, $session->teacher_id, [$newTime], $session->duration_min, [$session->id]);
    }

    /**
     * $proposedAt قد تكون null — "تبليغ غياب طارئ" بلا موعد بديل مقترح (المعلم/الطالب
     * يعجز عن حضور الجلسة ويريد فقط إبلاغ الإدارة لتنسيق البديل لاحقاً عبر approve()
     * بموعد بديل). عندها يصبح $reason إلزامياً دائماً (لا شيء آخر يشرح الطلب للأدمن).
     */
    public function request(ClassSession $session, User $requester, string $requesterRole, ?Carbon $proposedAt, ?string $reason = null): RescheduleRequest
    {
        if (in_array($session->status, ['completed', 'cancelled', 'no_show_student', 'no_show_teacher'], true)) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن طلب تغيير موعد لجلسة منتهية أو ملغاة'],
            ]);
        }

        // لا شيء يضبط status='active' فعلياً، فجلسة بدأت أو انتهت زمنياً تبقى
        // 'scheduled' وتفلت من الفحص أعلاه — نمنعها هنا بالوقت: بمجرد بلوغ
        // موعد بدايتها لا معنى لطلب تغيير موعدها (بدأت / تعمل الآن / انتهت).
        if (now()->gte($session->scheduled_at)) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن طلب تغيير موعد جلسة بدأت بالفعل أو انتهت'],
            ]);
        }

        // موعد جلسة الباقة الجماعية مشترك بين عدة طلاب دفعوا عليه معاً — تغييره
        // بطلب طالب أو معلم واحد يكسر جدول بقية الطلاب بلا أي تنسيق بينهم، ولا
        // مسار موافقة جماعي لذلك حالياً. القرار المعماري: تغيير الموعد متاح فقط
        // لجلسات الباقات الفردية.
        // جلسات الدورة التدريبية مشتركة بين كل المسجّلين فيها — نفس منطق الباقة الجماعية
        if ($session->course_id !== null || $session->loadMissing('booking.package')->booking?->package?->session_format === 'group') {
            throw ValidationException::withMessages([
                'session' => ['طلبات تغيير الموعد متاحة فقط لجلسات الباقات الفردية'],
            ]);
        }

        $this->assertSessionIsPaid($session, $requester, $requesterRole);

        if ($proposedAt) {
            $this->assertProposedTimeFarEnough($proposedAt, 'الموعد المقترح');
            $this->assertNewTimeHasNoConflict($session, $proposedAt);
        } elseif (empty($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['السبب إلزامي عند تبليغ غياب بلا موعد بديل مقترح'],
            ]);
        }

        $freeWindowHours = (int) $this->settings->get('reschedule_free_window_hours', 24);
        $hoursBeforeSession = max(0, now()->diffInHours($session->scheduled_at, false));
        $withinFreeWindow = $hoursBeforeSession < $freeWindowHours;

        if (! $withinFreeWindow && empty($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['السبب إلزامي عند طلب التغيير قبل أكثر من '.$freeWindowHours.' ساعة من الجلسة'],
            ]);
        }

        $maxPerSession = (int) $this->settings->get('reschedule_max_per_session', 1);
        $existingCount = RescheduleRequest::where('class_session_id', $session->id)
            ->where('status', 'pending')
            ->count();

        if ($existingCount >= $maxPerSession) {
            throw ValidationException::withMessages([
                'session' => ['يوجد طلب تغيير موعد قيد المراجعة بالفعل لهذه الجلسة'],
            ]);
        }

        $counterpartyDeadline = $proposedAt ? $this->counterpartyDeadlineFor($session->scheduled_at) : null;

        $rescheduleRequest = RescheduleRequest::create([
            'class_session_id' => $session->id,
            'booking_id' => $session->booking_id,
            'type' => RescheduleRequest::TYPE_RESCHEDULE,
            'requested_by' => $requester->id,
            'requester_role' => $requesterRole,
            'current_scheduled_at' => $session->scheduled_at,
            'proposed_scheduled_at' => $proposedAt,
            'within_free_window' => $withinFreeWindow,
            'hours_before_session' => $hoursBeforeSession,
            'reason' => $reason,
            'status' => 'pending',
            'counterparty_response' => $counterpartyDeadline ? 'pending' : null,
            'counterparty_deadline' => $counterpartyDeadline,
            // SLA الأدمن يبدأ حين يصله الطلب فعلاً — فوراً في المسار اليدوي، أو عند التصعيد
            'sla_due_at' => $counterpartyDeadline ? null : $this->adminSlaDueAt(),
        ]);

        if ($counterpartyDeadline) {
            $this->notifyCounterpartyRequested($session, $rescheduleRequest, $requester);
        } else {
            $this->notifyRequestSubmitted($session, $rescheduleRequest, $requesterRole);
        }

        return $rescheduleRequest;
    }

    /**
     * null = لا مسار تلقائي لهذا الطلب: معطَّل من الإعدادات، أو لم يبقَ قبل $anchor وقت يكفي
     * لرد الطرف الآخر ثم تصرّف الأدمن إن رفض. $anchor = موعد الجلسة التي سيُنقل موعدها،
     * أو الموعد المقترح للتعويضية (الجلسة الأصلية فائتة أصلاً).
     */
    private function counterpartyDeadlineFor(Carbon $anchor): ?Carbon
    {
        if ($this->settings->get('reschedule_requires_admin', false)) {
            return null;
        }

        $responseHours = (int) $this->settings->get('counterparty_response_hours', 12);
        // المهلة تنتهي قبل $anchor بهذه المدة على الأقل — ليبقى للأدمن وقت يتصرف فيه
        $cutoffHours = max(0, (int) $this->settings->get('counterparty_cutoff_hours_before_session', 2));
        $deadline = now()->addHours($responseHours)
            ->min($anchor->copy()->subHours($cutoffHours));

        // أقل من ساعة للرد لا تكفي فعلياً — الأدمن أسرع وأضمن
        return $deadline->gt(now()->addHour()) ? $deadline : null;
    }

    /** أقل مهلة بالساعات بين إرسال الطلب والموعد المقترح (0 = يكفي أن يكون في المستقبل) */
    public function minHoursAhead(): int
    {
        return max(0, (int) $this->settings->get('reschedule_min_hours_ahead', 2));
    }

    /**
     * يمنع اقتراح موعد قريب جداً لا يكفي ليرى الطرف الآخر الطلب ويستعد له —
     * لطلبات الطالب/المعلم فقط، لا لموعد بديل يحدده الأدمن.
     */
    private function assertProposedTimeFarEnough(Carbon $proposedAt, string $subject): void
    {
        $minHours = $this->minHoursAhead();

        if ($minHours > 0 && $proposedAt->lt(now()->addHours($minHours))) {
            throw ValidationException::withMessages([
                'proposed_scheduled_at' => [$subject.' يجب أن يكون بعد '.$minHours.' ساعة على الأقل من الآن'],
            ]);
        }
    }

    private function adminSlaDueAt(): Carbon
    {
        return now()->addHours((int) $this->settings->get('sla_reschedule_hours', 12));
    }

    /**
     * الطرف الآخر في جلسة فردية: المعلم إن طلب الطالب، وطالب الحجز إن طلب المعلم.
     */
    public function counterpartyUser(RescheduleRequest $rescheduleRequest): ?User
    {
        $session = $rescheduleRequest->loadMissing(['session.teacher.user', 'session.booking.student.user'])->session;

        return $rescheduleRequest->requester_role === 'student'
            ? $session?->teacher?->user
            : $session?->booking?->student?->user;
    }

    public function isAwaitingCounterparty(RescheduleRequest $rescheduleRequest): bool
    {
        return $rescheduleRequest->status === 'pending'
            && $rescheduleRequest->counterparty_response === 'pending'
            && $rescheduleRequest->counterparty_deadline?->isFuture();
    }

    /**
     * رد الطرف الآخر على الاقتراح. القبول يطبّق الموعد فوراً (بعد إعادة فحص التعارض —
     * قد يكون حُجز شيء آخر في هذا الموعد منذ الطلب)، والرفض يصعّد الطلب للأدمن.
     * القفل يمنع ردّين متزامنين (أو رداً متزامناً مع قرار أدمن) من تطبيق الطلب مرتين.
     */
    public function respond(RescheduleRequest $rescheduleRequest, User $user, bool $accept): RescheduleRequest
    {
        $newTime = DB::transaction(function () use ($rescheduleRequest, $user, $accept) {
            $locked = RescheduleRequest::whereKey($rescheduleRequest->id)->lockForUpdate()->firstOrFail();

            if (! $this->isAwaitingCounterparty($locked)) {
                throw ValidationException::withMessages([
                    'status' => ['هذا الطلب لم يعد بانتظار ردك'],
                ]);
            }

            if (! $accept) {
                $locked->update([
                    'counterparty_response' => 'declined',
                    'counterparty_responded_at' => now(),
                    'escalated' => true,
                    'sla_due_at' => $this->adminSlaDueAt(),
                ]);

                $this->audit('reschedule.declined_by_counterparty', $locked, [], ['escalated' => true], null, $user->id);

                return null;
            }

            $session = $locked->session;
            $newTime = $locked->proposed_scheduled_at;

            if ($locked->isMakeup()) {
                if ($newTime->lte(now())) {
                    throw ValidationException::withMessages([
                        'status' => ['لم يعد ممكناً اعتماد هذا الموعد — الموعد المقترح مضى'],
                    ]);
                }

                // تُنشئ التعويضية وتُبلّغ الطرفين (MakeupSessionScheduled) — بفحص التعارض والأهلية من جديد
                $this->sessions->scheduleMakeupByAgreement($session, $user, $newTime, $locked->id);
            } elseif ($session->status !== 'scheduled' || now()->gte($session->scheduled_at) || $newTime->lte(now())) {
                throw ValidationException::withMessages([
                    'status' => ['لم يعد ممكناً تطبيق هذا الموعد — الجلسة بدأت أو الموعد المقترح مضى'],
                ]);
            } else {
                $this->assertNewTimeHasNoConflict($session, $newTime);
                $this->applyNewTime($session, $newTime);
            }

            $locked->update([
                'status' => 'approved',
                'counterparty_response' => 'accepted',
                'counterparty_responded_at' => now(),
                'reviewed_at' => now(),
            ]);

            $this->audit('reschedule.accepted_by_counterparty', $locked, [], [
                'session_id' => $session->id,
                'new_scheduled_at' => $newTime->toDateTimeString(),
            ], null, $user->id);

            return $newTime;
        });

        $rescheduleRequest->refresh();

        // التعويضية: الطرفان بُلِّغا بـ MakeupSessionScheduled عند إنشائها
        if ($accept && ! $rescheduleRequest->isMakeup()) {
            $this->notifyBothParties(
                $rescheduleRequest,
                new RescheduleRequestReviewed('approved', $newTime, byCounterparty: true),
                'reschedule.accepted_by_counterparty',
            );
        } elseif (! $accept) {
            $this->notifyEscalated($rescheduleRequest, 'declined');
        }

        return $rescheduleRequest;
    }

    /**
     * مهلة رد الطرف الآخر انقضت بلا رد — يُصعَّد الطلب للأدمن. يستدعيه
     * EscalateRescheduleRequestsJob كل دقيقة؛ الشرط داخل update نفسه يمنع
     * تصعيد طلب ردّ عليه الطرف الآخر في اللحظة ذاتها.
     */
    public function escalateTimedOut(): int
    {
        $escalated = 0;

        RescheduleRequest::query()
            ->where('status', 'pending')
            ->where('counterparty_response', 'pending')
            ->where('counterparty_deadline', '<=', now())
            ->get()
            ->each(function (RescheduleRequest $rescheduleRequest) use (&$escalated) {
                $updated = RescheduleRequest::whereKey($rescheduleRequest->id)
                    ->where('status', 'pending')
                    ->where('counterparty_response', 'pending')
                    ->update([
                        'counterparty_response' => 'timeout',
                        'escalated' => true,
                        'sla_due_at' => $this->adminSlaDueAt(),
                        'updated_at' => now(),
                    ]);

                if (! $updated) {
                    return;
                }

                $escalated++;
                $rescheduleRequest->refresh();
                $this->audit('reschedule.counterparty_timeout', $rescheduleRequest, [], ['escalated' => true]);
                $this->notifyEscalated($rescheduleRequest, 'timeout');
            });

        return $escalated;
    }

    private function notifyCounterpartyRequested(ClassSession $session, RescheduleRequest $rescheduleRequest, User $requester): void
    {
        $counterparty = $this->counterpartyUser($rescheduleRequest);

        $this->notifications->send(
            $requester,
            new RescheduleRequestSubmitted(
                $rescheduleRequest->current_scheduled_at,
                $rescheduleRequest->proposed_scheduled_at,
                $rescheduleRequest->requester_role,
                $rescheduleRequest->reason,
                awaitingCounterparty: true,
                kind: $rescheduleRequest->type,
            ),
            'reschedule.submitted',
        );

        if ($counterparty) {
            $this->notifications->send(
                $counterparty,
                new RescheduleResponseRequested(
                    $session->id,
                    $rescheduleRequest->id,
                    $requester->name,
                    $rescheduleRequest->requester_role,
                    $rescheduleRequest->current_scheduled_at,
                    $rescheduleRequest->proposed_scheduled_at,
                    $rescheduleRequest->counterparty_deadline,
                    $rescheduleRequest->reason,
                    $rescheduleRequest->type,
                ),
                'reschedule.response_requested',
            );
        }
    }

    /**
     * الأدمن: قراره مطلوب الآن. صاحب الطلب: الطرف الآخر رفض/لم يرد وأُحيل طلبه للإدارة.
     * الطرف الآخر عند انقضاء المهلة: فاته الرد والقرار صار للإدارة.
     */
    private function notifyEscalated(RescheduleRequest $rescheduleRequest, string $cause): void
    {
        $notification = fn (bool $forReviewer) => new RescheduleRequestEscalated(
            $cause,
            $rescheduleRequest->current_scheduled_at,
            $rescheduleRequest->proposed_scheduled_at,
            $rescheduleRequest->requester_role,
            $forReviewer,
            $rescheduleRequest->type,
        );

        collect([
            $rescheduleRequest->loadMissing('requester')->requester,
            $cause === 'timeout' ? $this->counterpartyUser($rescheduleRequest) : null,
        ])
            ->filter()
            ->unique('id')
            ->each(fn (User $party) => $this->notifications->send($party, $notification(false), 'reschedule.escalated'));

        User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get()
            ->each(fn (User $admin) => $this->notifications->send($admin, $notification(true), 'reschedule.escalated'));
    }

    private function notifyBothParties(RescheduleRequest $rescheduleRequest, Notification $notification, string $event): void
    {
        $rescheduleRequest->loadMissing(['session.teacher.user', 'session.booking.student.user']);

        collect([
            $rescheduleRequest->session?->teacher?->user,
            $rescheduleRequest->session?->booking?->student?->user,
        ])
            ->filter()
            ->unique('id')
            ->each(fn (User $party) => $this->notifications->send($party, $notification, $event));
    }

    /**
     * تطبيق موعد جديد على الجلسة — مشترك بين موافقة الأدمن وقبول الطرف الآخر.
     */
    private function applyNewTime(ClassSession $session, Carbon $newTime): void
    {
        $session->update([
            'original_scheduled_at' => $session->original_scheduled_at ?? $session->scheduled_at,
            'scheduled_at' => $newTime,
            'status' => 'scheduled',
            // التذكير السابق (إن أُرسل) يحمل الموعد القديم، وبقاء reminder_sent_at
            // مضبوطاً يمنع SendSessionRemindersJob من إرسال تذكير بالموعد الجديد.
            'reminder_sent_at' => null,
        ]);
    }

    /**
     * فور تسجيل الطلب: الطالب والمعلم يتلقّيان تأكيد الاستلام، وكل أدمن نشط
     * يتلقّى تنبيهاً بأن قراره مطلوب (الموافقة إلزامية دائماً — FR-8.1). القناتان
     * mail + database، فيصل بريد ويظهر تنبيه على جرس لوحة التحكم معاً — تماماً
     * كإشعار مراجعة الطلب (RescheduleRequestReviewed) الموجود أصلاً.
     */
    private function notifyRequestSubmitted(ClassSession $session, RescheduleRequest $rescheduleRequest, string $requesterRole): void
    {
        $session->loadMissing(['teacher.user', 'booking.student.user']);

        $parties = collect([
            $session->teacher?->user,
            $session->booking?->student?->user,
        ])->filter()->unique('id');

        foreach ($parties as $partyUser) {
            $this->notifications->send(
                $partyUser,
                new RescheduleRequestSubmitted(
                    $rescheduleRequest->current_scheduled_at,
                    $rescheduleRequest->proposed_scheduled_at,
                    $requesterRole,
                    $rescheduleRequest->reason,
                    kind: $rescheduleRequest->type,
                ),
                'reschedule.submitted',
            );
        }

        User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get()
            ->each(fn (User $admin) => $this->notifications->send(
                $admin,
                new RescheduleRequestSubmitted(
                    $rescheduleRequest->current_scheduled_at,
                    $rescheduleRequest->proposed_scheduled_at,
                    $requesterRole,
                    $rescheduleRequest->reason,
                    forReviewer: true,
                    kind: $rescheduleRequest->type,
                ),
                'reschedule.submitted',
            ));
    }

    /**
     * الجلسات تُنشأ فور موافقة المعلم/انضمام الطالب بصرف النظر عن اكتمال
     * الدفع (نفس منطق ScheduleConflictService) — فحجز/تسجيل بحالة
     * pending_payment له جلسات حقيقية بالفعل على الجدول رغم أنه غير مؤهل
     * لأي طلب تعديل عليها بعد؛ يبقى معلَّقاً حتى تُنشأ سلفاً أو يُلغى تلقائياً
     * (hold_expires_at) لو فشل الدفع.
     */
    private function assertSessionIsPaid(ClassSession $session, User $requester, string $requesterRole): void
    {
        $status = $this->resolvePaymentStatus($session, $requester, $requesterRole);

        if ($status === 'pending_payment') {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تغيير موعد جلسة غير مدفوعة.'],
            ]);
        }

        // expired: انتهت مهلة الدفع المؤقتة دون إكمالها. cancelled/withdrawn:
        // أُلغي الاشتراك صراحةً. في الحالتين لم يعد اشتراك الطالب/الحجز قائماً
        // فعلاً، فلا معنى لتغيير موعد جلسة لم يعد يملك حقاً فيها.
        if (in_array($status, ['expired', 'cancelled', 'withdrawn'], true)) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تغيير موعد جلسة اشتراكها لم يعد قائماً.'],
            ]);
        }
    }

    /**
     * للطالب: حجزه/تسجيله الخاص تحديداً عبر سطر حضوره — لا حجز الجلسة العام،
     * فهذا الأخير يخص أول طالب انضمّ فقط في الباقات الجماعية (session.booking_id
     * ثابت لصاحب أول انضمام)، بينما لكل طالب لاحق حجزه/تسجيله المستقل الخاص.
     * للمعلم: حجز/تسجيل الجلسة نفسها (لا يوجد مفهوم دفع خاص بالمعلم).
     */
    private function resolvePaymentStatus(ClassSession $session, User $requester, string $requesterRole): ?string
    {
        if ($requesterRole === 'student') {
            $studentId = $requester->loadMissing('student')->student?->id;
            $attendee = $session->attendees()->where('student_id', $studentId)->first();

            if ($attendee?->booking_id) {
                return $attendee->booking?->status;
            }

            if ($attendee?->enrollment_id) {
                return $attendee->enrollment?->status;
            }
        }

        if ($session->booking_id) {
            return $session->loadMissing('booking')->booking?->status;
        }

        return null;
    }

    public function approve(RescheduleRequest $rescheduleRequest, User $admin, ?Carbon $alternativeAt = null, ?string $notes = null): RescheduleRequest
    {
        if ($rescheduleRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الموافقة إلا على طلب بانتظار المراجعة'],
            ]);
        }

        $newTime = $alternativeAt ?? $rescheduleRequest->proposed_scheduled_at;

        // طلب "تبليغ غياب" (proposed_scheduled_at فارغة أصلاً — لا موعد مقترح من
        // صاحب الطلب) لا يملك شيئاً تُطبَّق الموافقة البسيطة عليه؛ الأدمن مُلزَم
        // بتمرير $alternativeAt بعد تنسيقه فعلياً مع الطرفين خارج المنصة.
        if (! $newTime) {
            throw ValidationException::withMessages([
                'alternative_scheduled_at' => ['هذا طلب تبليغ غياب بلا موعد مقترح — يجب تحديد موعد بديل للموافقة عليه'],
            ]);
        }

        $rescheduleRequest->loadMissing('session');
        $session = $rescheduleRequest->session;

        if ($rescheduleRequest->isMakeup()) {
            // تُنشئ التعويضية بالموعد المعتمد وتُبلّغ الطرفين (MakeupSessionScheduled) بشروط الأدمن
            $this->sessions->scheduleMakeupByAdmin($session, $admin, $newTime, $notes, $rescheduleRequest->id);
        } else {
            // فحص ملزم مستقل عن فحص request() — الوقت بينهما قد يشهد حجوزاً آخر
            // يتعارض مع نفس هذا الموعد المقترح (نفس مبدأ إعادة الفحص عند approveIndividualRequest).
            $this->assertNewTimeHasNoConflict($session, $newTime);

            $this->applyNewTime($session, $newTime);
        }

        $rescheduleRequest->update([
            'status' => $alternativeAt ? 'approved_with_alternative' : 'approved',
            'admin_alternative_at' => $alternativeAt,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $notes,
        ]);

        $this->audit('reschedule.approved', $rescheduleRequest, [], [
            'session_id' => $session->id,
            'new_scheduled_at' => $newTime->toDateTimeString(),
        ], $notes, $admin->id);

        // يُبلَّغ الطرفان معاً — الطالب والمعلم — لا صاحب الطلب وحده: نفس سبب
        // reject() بالضبط (الموعد الجديد يخص جدول الطرفين كليهما، لا صاحب الطلب
        // فقط). قبل هذا الإصلاح كان الطرف الآخر (الذي لم يُرسل الطلب) لا يُبلَّغ
        // إطلاقاً رغم أن جلسته هو أيضاً تغيّر موعدها فعلياً. (التعويضية بُلِّغت عند إنشائها.)
        if (! $rescheduleRequest->isMakeup()) {
            $this->notifyBothParties(
                $rescheduleRequest,
                new RescheduleRequestReviewed($rescheduleRequest->status, $newTime, adminNotes: $notes),
                'reschedule.approved',
            );
        }

        return $rescheduleRequest->fresh();
    }

    /**
     * تعديل مباشر لموعد جلسة من الأدمن، بلا طلب تغيير موعد مسبق، ولأي نوع جلسة
     * (فردية/جماعية/دورة). رابط الجلسة لا يتأثر إطلاقاً: روابط BBB مبنية من
     * meetingID وكلمة المرور فقط (لا وقت فيها)، فلا تُلمَس حقول BBB وروابط الانضمام.
     *
     * القيود تحمي المستحقات المالية ورصيد الحصص:
     *   - جلسة scheduled لم تبدأ بعد فقط — الجلسة المنتهية/الملغاة قد تكون ضمن
     *     مستحقات صُرفت فعلاً.
     *   - الموعد الجديد في المستقبل — وإلا يحوّلها completeEndedSessions إلى
     *     completed خلال دقيقة فتدخل المستحقات دون أن تُعقد.
     *   - لا حضور/غياب مسجَّل عليها (present/absent/partial) — هذا خصم فعلي من
     *     رصيد الطالب على جلسة لم تُعقد بعد بموعدها الجديد.
     *   - اعتذار الطالب المسبق (excused) كان عن الموعد القديم، فيُعاد إلى registered.
     */
    public function adminReschedule(ClassSession $session, User $admin, Carbon $newTime, ?string $notes = null): ClassSession
    {
        if ($session->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تعديل موعد جلسة منتهية أو ملغاة'],
            ]);
        }

        if (now()->gte($session->scheduled_at)) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تعديل موعد جلسة بدأت بالفعل أو انتهت'],
            ]);
        }

        if ($newTime->lte(now())) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['يجب أن يكون الموعد الجديد في المستقبل'],
            ]);
        }

        if ($newTime->eq($session->scheduled_at)) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['الموعد الجديد مطابق للموعد الحالي'],
            ]);
        }

        if ($session->attendees()->whereIn('attendance', ['present', 'absent', 'partial'])->exists()) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تعديل موعد جلسة سُجِّل عليها حضور أو غياب'],
            ]);
        }

        $this->assertNoConflictForAllParties($session, $newTime);

        $previousTime = $session->scheduled_at->copy();

        DB::transaction(function () use ($session, $admin, $newTime, $notes, $previousTime) {
            $session->update([
                'original_scheduled_at' => $session->original_scheduled_at ?? $session->scheduled_at,
                'scheduled_at' => $newTime,
                'reminder_sent_at' => null,
            ]);

            $session->attendees()->where('attendance', 'excused')->update([
                'attendance' => 'registered',
                'absence_notified_at' => null,
                'absence_reason' => null,
                'deducted_from_balance' => false,
            ]);

            // أي طلب تغيير موعد معلّق على هذه الجلسة لم يعد له معنى بعد أن حسم
            // الأدمن موعدها مباشرة — يُغلق بدل أن يبقى معلّقاً فيُعتمد لاحقاً خطأً
            // فوق الموعد الجديد. الطرفان يصلهما إشعار الموعد الجديد أدناه.
            RescheduleRequest::where('class_session_id', $session->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => 'تم تعديل موعد الجلسة مباشرة من الإدارة',
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);

            $this->audit('session.rescheduled_by_admin', $session, [
                'scheduled_at' => $previousTime->toDateTimeString(),
            ], [
                'scheduled_at' => $newTime->toDateTimeString(),
            ], $notes, $admin->id);
        });

        $session->loadMissing(['teacher.user', 'attendees.student.user']);

        collect([$session->teacher?->user])
            ->merge($session->attendees->map(fn ($attendee) => $attendee->student?->user))
            ->filter()
            ->unique('id')
            ->each(fn (User $party) => $this->notifications->send(
                $party,
                new SessionRescheduledByAdmin($previousTime, $newTime, $notes),
                'session.rescheduled_by_admin',
            ));

        return $session->fresh();
    }

    /**
     * assertNewTimeHasNoConflict يفحص طالب الحجز وحده (كافٍ لطلبات الباقات الفردية).
     * تعديل الأدمن يشمل الجلسات الجماعية والدورات أيضاً، فيُفحص كل طالب باشتراك
     * قائم على الجلسة — وجدول المعلم وحده إن لم يكن عليها أي طالب بعد.
     */
    private function assertNoConflictForAllParties(ClassSession $session, Carbon $newTime): void
    {
        $attendeesQuery = $session->attendees()->with('student');
        ClassSession::constrainToLiveAttendee($attendeesQuery);
        $students = $attendeesQuery->get()->pluck('student')->filter();

        if ($students->isEmpty()) {
            $this->conflicts->assertNoConflict(null, $session->teacher_id, [$newTime], $session->duration_min, [$session->id]);

            return;
        }

        foreach ($students as $student) {
            $this->conflicts->assertNoConflict($student, $session->teacher_id, [$newTime], $session->duration_min, [$session->id]);
        }
    }

    public function reject(RescheduleRequest $rescheduleRequest, User $admin, string $reason): RescheduleRequest
    {
        if ($rescheduleRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن الرفض إلا لطلب بانتظار المراجعة'],
            ]);
        }

        $rescheduleRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $this->audit('reschedule.rejected', $rescheduleRequest, [], ['status' => 'rejected'], $reason, $admin->id);

        // يُبلَّغ الطرفان معاً — الطالب والمعلم — لا صاحب الطلب وحده: رفض التغيير
        // يعني بقاء الجلسة على وقتها الأصلي، وهذا يخص جدول الطرفين كليهما (صاحب
        // الطلب دائماً أحدهما، فلا يفقد أحد إشعاره). القناتان mail + database،
        // فيصل بريد ويظهر تنبيه على جرس لوحة التحكم معاً.
        $rescheduleRequest->loadMissing(['session.teacher.user', 'session.booking.student.user']);

        collect([
            $rescheduleRequest->session?->teacher?->user,
            $rescheduleRequest->session?->booking?->student?->user,
        ])
            ->filter()
            ->unique('id')
            ->each(fn (User $party) => $this->notifications->send(
                $party,
                new RescheduleRequestReviewed('rejected', reason: $reason, kind: $rescheduleRequest->type),
                'reschedule.rejected',
            ));

        return $rescheduleRequest->fresh();
    }
}
