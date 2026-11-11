<?php

namespace App\Http\Resources\Session;

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
            'status' => $this->status,
            // داخلي للأدمن فقط (ClassSession::$hidden) — لا أثر ظاهر للطالب/المعلم
            'attendance_outcome' => $this->when($isAdmin, fn () => $this->attendance_outcome),
            'teacher_payout_percent' => $this->when($isAdmin, fn () => $this->teacher_payout_percent),
            'teacher_compensation_amount' => $this->when($isAdmin, fn () => $this->teacher_compensation_amount),
            // {amount, paid} أو null — يضبطه ClassSessionController::index للأدمن فقط
            'teacher_payout_amount' => $this->when(
                $isAdmin && array_key_exists('payout_amount', $this->resource->getAttributes()),
                fn () => $this->payout_amount,
            ),
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
