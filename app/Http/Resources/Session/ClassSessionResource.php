<?php

namespace App\Http\Resources\Session;

use App\Models\ClassSession;
use App\Models\RescheduleRequest;
use App\Models\SessionJoinLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * رابط الانضمام يُعرض حسب دور طالب النداء فقط — لا يُسرَّب رابط/كلمة سر
 * الطرف الآخر (الطالب لا يرى join_url_teacher والعكس). الأدمن يرى الاثنين.
 */
class ClassSessionResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user?->isAdmin() ?? false;
        $isTeacher = $isAdmin || $user?->loadMissing('teacher')->teacher?->id === $this->teacher_id;
        // الرابط الخام يذهب لـ BBB مباشرة بلا أي فحص من المنصة، وكلمة مرور الحضور
        // فيه مشتركة بين كل طلاب الجلسة — فيُعرض فقط لمن دفع فعلاً، وإلا نسخه صاحب
        // حجز pending_payment ودخل به لاحقاً حتى بعد انتهاء مهلته.
        $studentId = $user?->loadMissing('student')->student?->id;
        $isStudent = $isAdmin || ($studentId && $this->resource->studentHasPaidAccess($studentId));
        $pendingRescheduleRequest = $this->relationLoaded('rescheduleRequests')
            ? $this->rescheduleRequests->first()
            : null;

        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'course_id' => $this->course_id,
            'teacher_id' => $this->teacher_id,
            'sequence_no' => $this->sequence_no,
            'scheduled_at' => $this->scheduled_at,
            'duration_min' => $this->duration_min,
            'original_scheduled_at' => $this->original_scheduled_at,
            // للأدمن فقط: يعرض مواعيد الجلسة ويُدخلها بها، لا بتوقيت متصفحه (ClassSession::studentTimezone)
            'student_timezone' => $this->when($isAdmin, fn () => $this->resource->studentTimezone()),
            'status' => $this->status,
            // شارة "الجلسات" لدى الطالب والمعلم — نفس اشتقاق فلترها (ClassSession::scopeWhereDisplayStatus)
            'display_status' => $this->resource->displayStatus((bool) $pendingRescheduleRequest),
            // لصاحب الجلسة والأدمن: لماذا لم تُعقد (غياب الطالب/غيابه هو/اشتراك غير مدفوع) — بلا المستحق
            'missed_reason' => $this->when($isTeacher, fn () => $this->resource->missedReason()),
            // داخلي للأدمن فقط (ClassSession::$hidden) — لا أثر ظاهر للطالب/المعلم
            'attendance_outcome' => $this->when($isAdmin, fn () => $this->attendance_outcome),
            'teacher_payout_percent' => $this->when($isAdmin, fn () => $this->teacher_payout_percent),
            'teacher_compensation_amount' => $this->when($isAdmin, fn () => $this->teacher_compensation_amount),
            'teacher_compensation_percent' => $this->when($isAdmin, fn () => $this->teacher_compensation_percent),
            // {amount, paid} أو null — يضبطه ClassSessionController::index للأدمن فقط
            'teacher_payout_amount' => $this->when(
                $isAdmin && array_key_exists('payout_amount', $this->resource->getAttributes()),
                fn () => $this->payout_amount,
            ),
            // جلسة باقة حملة: لا مستحق عنها للمعلم — يغطيها راتبه الشهري الثابت
            'covered_by_salary' => $this->whenLoaded('booking', fn () => (bool) $this->booking?->excluded_from_payouts),
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'cancellation_reason' => $this->cancellation_reason,
            'is_makeup' => $this->is_makeup,
            'makeup_for_session_id' => $this->makeup_for_session_id,
            // يُحمَّل للأدمن في قائمة الجلسات فقط (withExists) — غائب عن غيره
            'has_makeup' => $this->when(
                $isAdmin && array_key_exists('has_makeup', $this->resource->getAttributes()),
                fn () => (bool) $this->has_makeup,
            ),
            'recording_url' => $this->recording_url,
            'has_pending_reschedule_request' => (bool) $pendingRescheduleRequest,
            'pending_reschedule_request_status' => $pendingRescheduleRequest?->status,
            'pending_reschedule_request_created_at' => $pendingRescheduleRequest?->created_at,
            // تفاصيل الطلب المعلّق لطرفي الجلسة — awaiting_my_response يُظهر زر "الرد على الطلب"
            // للطرف الآخر وحده، طوال مهلة رده (المسار التلقائي في RescheduleService)
            'pending_reschedule_request' => $pendingRescheduleRequest ? [
                'id' => $pendingRescheduleRequest->id,
                // makeup: current_scheduled_at = الجلسة الفائتة، والموافقة تُنشئ تعويضية بالموعد المقترح
                'type' => $pendingRescheduleRequest->type,
                'requester_role' => $pendingRescheduleRequest->requester_role,
                'requested_by_me' => $user?->id === $pendingRescheduleRequest->requested_by,
                'current_scheduled_at' => $pendingRescheduleRequest->current_scheduled_at,
                'proposed_scheduled_at' => $pendingRescheduleRequest->proposed_scheduled_at,
                'reason' => $pendingRescheduleRequest->reason,
                'counterparty_response' => $pendingRescheduleRequest->counterparty_response,
                'counterparty_deadline' => $pendingRescheduleRequest->counterparty_deadline,
                'awaiting_my_response' => $this->awaitingResponseFrom($user, $pendingRescheduleRequest, $isAdmin),
            ] : null,
            // زر "اقتراح موعد تعويضي" لطرفي جلسة فردية فائتة غاب عنها المعلم (السياسة: تعويض
            // مجاني لغياب المعلم وحده) — RescheduleService::requestMakeup يفرض نفس الشروط
            'can_request_makeup' => $this->canRequestMakeup($user, $isAdmin, $pendingRescheduleRequest),
            // لصاحب الجلسة: لماذا لا يظهر زر التعويض لجلسة غاب عنها (يُعرض مكان الزر)
            'makeup_note' => $this->when($isTeacher && ! $isAdmin, fn () => $this->makeupNote($user, $pendingRescheduleRequest)),
            // مسار صفحة تقييم الجودة (/rate-session/{token}) لزر "نسخ رابط التقييم" — لصاحب
            // الجلسة والأدمن فقط، بعد بدئها ودخول المعلم والطالب إليها (الرمز يُنشأ عند أول طلب له)
            'quality_rating_path' => $this->when(
                $isTeacher && \App\Services\SessionQualityRatingService::linkAvailable($this->resource),
                fn () => \App\Models\SessionQualityRating::frontendPath($this->resource->ensureQualityRatingToken()),
            ),
            'join_url_teacher' => $isTeacher ? $this->join_url_teacher : null,
            'join_url_student' => $isStudent ? $this->join_url_student : null,
            // ما يفتحه زر "انضم" فعلاً: رابط عادي يمرّ عبر المنصة (joinRedirect) فيتحقق من
            // الموعد، ويُنشئ الغرفة إن حذفها BBB، ويسجّل الحضور ثم يحوّل لـ BBB — بلا
            // window.open بعد await الذي يحجبه Safari/موانع النوافذ فيعيد المستخدم الكبس.
            'join_redirect_url' => $this->joinRedirectUrl($user, $isTeacher, $isStudent),
            'teacher' => $this->whenLoaded('teacher'),
            'booking' => $this->whenLoaded('booking'),
            'course' => $this->whenLoaded('course'),
            'attendees' => $this->whenLoaded('attendees'),
            'reschedule_requests' => $this->whenLoaded('rescheduleRequests'),
            // تُحمَّل للأدمن، ولصاحب الجلسة في show فقط (ClassSessionController) — غائبة كلياً عن الطلاب
            'join_logs' => $this->whenLoaded('joinLogs'),
            // هل بدأت الجلسة بعد بدء تسجيل الانضمام — وإلا فغياب سجلها لا يعني غياباً
            'join_tracked' => $this->when(
                $this->relationLoaded('joinLogs'),
                fn () => ($since = SessionJoinLog::trackingStartedAt()) !== null && $this->scheduled_at?->gte($since),
            ),
        ];
    }

    /**
     * الطرف الآخر = من لم يرسل الطلب من طرفي الجلسة: المعلم إن طلب الطالب، وطالب الجلسة
     * إن طلب المعلم (الطلبات للجلسات الفردية فقط). بلا استعلام إضافي — من بيانات الجلسة المحمّلة.
     */
    private function awaitingResponseFrom(?User $user, RescheduleRequest $request, bool $isAdmin): bool
    {
        if (! $user || $isAdmin || $user->id === $request->requested_by
            || $request->counterparty_response !== 'pending' || ! $request->counterparty_deadline?->isFuture()) {
            return false;
        }

        if ($request->requester_role === 'student') {
            return $user->loadMissing('teacher')->teacher?->id === $this->teacher_id;
        }

        $studentId = $user->loadMissing('student')->student?->id;

        return $studentId !== null && $this->booking?->student_id === $studentId;
    }

    /**
     * سبب غياب زر "اقتراح موعد تعويضي" عن جلسة غاب عنها المعلم — null حين يظهر الزر أو لا علاقة
     * للجلسة بالتعويض. awaiting_other: طلب معلّق ينتظر رد الطرف الآخر؛ awaiting_admin: ينتظر
     * قرار الإدارة (المسار اليدوي أو بعد رفض/انقضاء مهلة الرد)؛ made: أُنشئت تعويضيتها؛
     * admin_decides: جماعية/دورة — تعويضها تحدده الإدارة. طلب ينتظر ردي أنا له زره الخاص.
     */
    private function makeupNote(?User $user, ?RescheduleRequest $pendingRequest): ?string
    {
        if ($this->status !== 'completed' || $this->resource->attendance_outcome !== ClassSession::OUTCOME_TEACHER_ABSENT
            || $this->canRequestMakeup($user, false, $pendingRequest)) {
            return null;
        }

        if ($pendingRequest) {
            if ($this->awaitingResponseFrom($user, $pendingRequest, false)) {
                return null;
            }

            return $pendingRequest->counterparty_response === 'pending' && $pendingRequest->counterparty_deadline?->isFuture()
                ? 'awaiting_other'
                : 'awaiting_admin';
        }

        $hasMakeup = array_key_exists('has_makeup', $this->resource->getAttributes())
            ? (bool) $this->resource->has_makeup
            : $this->resource->makeupSessions()->exists();

        if ($hasMakeup) {
            return 'made';
        }

        return $this->course_id !== null || $this->booking?->package?->session_format === 'group' ? 'admin_decides' : null;
    }

    /**
     * من بيانات الجلسة المحمّلة (has_makeup ضمن استعلام القائمة/التفاصيل) — نفس شروط
     * SessionService::isEligibleForMakeupRequest، مع اشتراك مدفوع للطالب صاحب الحجز.
     */
    private function canRequestMakeup(?User $user, bool $isAdmin, ?RescheduleRequest $pendingRequest): bool
    {
        if (! $user || $isAdmin || $pendingRequest
            || $this->status !== 'completed'
            || $this->resource->attendance_outcome !== ClassSession::OUTCOME_TEACHER_ABSENT
            || $this->course_id !== null
            || $this->booking?->package?->session_format === 'group'
            || ! in_array($this->booking?->status, ClassSession::PAID_BOOKING_STATUSES, true)) {
            return false;
        }

        $hasMakeup = array_key_exists('has_makeup', $this->resource->getAttributes())
            ? (bool) $this->resource->has_makeup
            : $this->resource->makeupSessions()->exists();

        if ($hasMakeup) {
            return false;
        }

        if ($user->loadMissing('teacher')->teacher?->id === $this->teacher_id) {
            return true;
        }

        $studentId = $user->loadMissing('student')->student?->id;

        return $studentId !== null
            && $this->booking?->student_id === $studentId;
    }

    private function joinRedirectUrl(?User $user, bool $isTeacher, bool $isStudent): ?string
    {
        $joinUrl = $isTeacher ? $this->join_url_teacher : ($isStudent ? $this->join_url_student : null);

        if (! $user || ! $joinUrl || ! $this->scheduled_at || ! $this->duration_min) {
            return null;
        }

        $endsAt = $this->scheduled_at->clone()->addMinutes($this->duration_min);

        if ($endsAt->isPast()) {
            return null;
        }

        return URL::temporarySignedRoute(
            'class-sessions.join-redirect',
            $endsAt,
            ['session' => $this->id, 'user' => $user->id],
        );
    }
}
