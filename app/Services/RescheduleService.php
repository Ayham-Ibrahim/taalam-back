<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Notifications\RescheduleRequestReviewed;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
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
    ) {}

    public function request(ClassSession $session, User $requester, string $requesterRole, Carbon $proposedAt, ?string $reason = null): RescheduleRequest
    {
        if (in_array($session->status, ['completed', 'cancelled', 'no_show_student', 'no_show_teacher'], true)) {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن طلب تغيير موعد لجلسة منتهية أو ملغاة'],
            ]);
        }

        // موعد جلسة الباقة الجماعية مشترك بين عدة طلاب دفعوا عليه معاً — تغييره
        // بطلب طالب أو معلم واحد يكسر جدول بقية الطلاب بلا أي تنسيق بينهم، ولا
        // مسار موافقة جماعي لذلك حالياً. القرار المعماري: تغيير الموعد متاح فقط
        // لجلسات الباقات الفردية.
        if ($session->loadMissing('booking.package')->booking?->package?->session_format === 'group') {
            throw ValidationException::withMessages([
                'session' => ['طلبات تغيير الموعد متاحة فقط لجلسات الباقات الفردية'],
            ]);
        }

        $this->assertSessionIsPaid($session, $requester, $requesterRole);

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

        return RescheduleRequest::create([
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
        if ($this->resolvePaymentStatus($session, $requester, $requesterRole) === 'pending_payment') {
            throw ValidationException::withMessages([
                'session' => ['لا يمكن تغيير موعد جلسة غير مدفوعة.'],
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

        $rescheduleRequest->loadMissing('session');
        $session = $rescheduleRequest->session;

        $session->update([
            'original_scheduled_at' => $session->original_scheduled_at ?? $session->scheduled_at,
            'scheduled_at' => $newTime,
            'status' => 'scheduled',
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

        // صاحب الطلب (requester) — طالب أو معلم، أيهما أرسله فعلياً — هو من
        // ينتظر القرار، بصرف النظر عمّن أنشأ الجلسة أصلاً.
        if ($requester = $rescheduleRequest->loadMissing('requester')->requester) {
            $this->notifications->send(
                $requester,
                new RescheduleRequestReviewed($rescheduleRequest->status, $newTime),
                'reschedule.approved',
            );
        }

        return $rescheduleRequest->fresh();
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

        if ($requester = $rescheduleRequest->loadMissing('requester')->requester) {
            $this->notifications->send(
                $requester,
                new RescheduleRequestReviewed('rejected', reason: $reason),
                'reschedule.rejected',
            );
        }

        return $rescheduleRequest->fresh();
    }
}
