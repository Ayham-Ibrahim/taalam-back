<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Notifications\RescheduleRequestReviewed;
use App\Notifications\RescheduleRequestSubmitted;
use App\Notifications\SessionRescheduledByAdmin;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * قرار معماري محسوم: لا يوجد مسار تلقائي لتغيير المواعيد إطلاقاً — كل طلب يبدأ
 * pending ويحتاج قرار أدمن صريح (approve/reject)، بصرف النظر عن نافذة الـ 24 ساعة.
 * الفرق بين ما قبل/بعد 24 ساعة هو فقط within_free_window (يحدد الأولوية) وإلزامية السبب.
 */
class RescheduleService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly ScheduleConflictService $conflicts,
    ) {}

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

        $rescheduleRequest = RescheduleRequest::create([
            'class_session_id' => $session->id,
            'booking_id' => $session->booking_id,
            'requested_by' => $requester->id,
            'requester_role' => $requesterRole,
            'current_scheduled_at' => $session->scheduled_at,
            'proposed_scheduled_at' => $proposedAt,
            'within_free_window' => $withinFreeWindow,
            'hours_before_session' => $hoursBeforeSession,
            'reason' => $reason,
            'status' => 'pending',
            'sla_due_at' => now()->addHours((int) $this->settings->get('sla_reschedule_hours', 12)),
        ]);

        $this->notifyRequestSubmitted($session, $rescheduleRequest, $requesterRole);

        return $rescheduleRequest;
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

        // فحص ملزم مستقل عن فحص request() — الوقت بينهما قد يشهد حجوزاً آخر
        // يتعارض مع نفس هذا الموعد المقترح (نفس مبدأ إعادة الفحص عند approveIndividualRequest).
        $this->assertNewTimeHasNoConflict($session, $newTime);

        $session->update([
            'original_scheduled_at' => $session->original_scheduled_at ?? $session->scheduled_at,
            'scheduled_at' => $newTime,
            'status' => 'scheduled',
            // التذكير السابق (إن أُرسل) يحمل الموعد القديم، وبقاء reminder_sent_at
            // مضبوطاً يمنع SendSessionRemindersJob من إرسال تذكير بالموعد الجديد.
            'reminder_sent_at' => null,
        ]);

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
        // إطلاقاً رغم أن جلسته هو أيضاً تغيّر موعدها فعلياً.
        $rescheduleRequest->loadMissing(['session.teacher.user', 'session.booking.student.user']);

        collect([
            $rescheduleRequest->session?->teacher?->user,
            $rescheduleRequest->session?->booking?->student?->user,
        ])
            ->filter()
            ->unique('id')
            ->each(fn (User $party) => $this->notifications->send(
                $party,
                new RescheduleRequestReviewed($rescheduleRequest->status, $newTime, adminNotes: $notes),
                'reschedule.approved',
            ));

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
                new RescheduleRequestReviewed('rejected', reason: $reason),
                'reschedule.rejected',
            ));

        return $rescheduleRequest->fresh();
    }
}
