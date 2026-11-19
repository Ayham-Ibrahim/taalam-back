<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\RescheduleRequest;
use App\Services\BookingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * كل ما ينتظر تصرّف الطالب/المعلم في مكان واحد — لبطاقة "بانتظار ردك" في لوحته الرئيسية،
 * والشريط أعلى صفحات اللوحة، ورقم "الجلسات" في القائمة. مستقل عن قائمة الجلسات (المقسّمة
 * لصفحات والمفلترة افتراضياً على القادمة، فتختفي منها الجلسة الفائتة القابلة للتعويض).
 *
 *  - awaiting_response: طلبات تغيير موعد/موعد تعويضي المستخدم هو الطرف الآخر فيها، ومهلة
 *    رده لم تنتهِ (نفس شرط RescheduleService::isAwaitingCounterparty).
 *  - makeup_eligible: جلسات فائتة غاب عنها المعلم يحق اقتراح موعد تعويضي لها (نفس شروط
 *    SessionService::isEligibleForMakeupRequest)، بلا طلب معلّق.
 *  - awaiting_payment (للطالب فقط): باقات/دورات بانتظار الدفع لم تنقضِ مهلتها — تضيع بانتهائها.
 *  - awaiting_confirmation (للمعلم فقط): طلبات حجز بانتظار موافقته، مهلتها أقرب موعد مطلوب —
 *    يبطل الطلب كله بمروره (BookingService::expireStalePendingTeacherConfirmations).
 */
class PendingActionsController extends Controller
{
    public function __construct(private readonly BookingService $bookingService) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $teacherId = $user->isTeacher() ? $user->loadMissing('teacher')->teacher?->id : null;
        $studentId = $user->isStudent() ? $user->loadMissing('student')->student?->id : null;

        if (! $teacherId && ! $studentId) {
            return $this->success(['count' => 0, 'awaiting_response' => [], 'makeup_eligible' => [], 'awaiting_payment' => [], 'awaiting_confirmation' => []]);
        }

        $awaitingPayment = $studentId ? $this->awaitingPayment($studentId) : collect();
        $awaitingConfirmation = $teacherId ? $this->awaitingConfirmation($teacherId) : collect();

        $awaiting = RescheduleRequest::query()
            ->where('status', 'pending')
            ->where('counterparty_response', 'pending')
            ->where('counterparty_deadline', '>', now())
            ->where('requested_by', '!=', $user->id)
            ->when($teacherId, fn ($q) => $q->where('requester_role', 'student')
                ->whereHas('session', fn ($s) => $s->where('teacher_id', $teacherId)))
            ->when($studentId, fn ($q) => $q->where('requester_role', 'teacher')
                ->whereHas('session.booking', fn ($b) => $b->where('student_id', $studentId)))
            ->with(['requester:id,name', 'session' => fn ($q) => $q->with(self::SESSION_RELATIONS)])
            ->orderBy('counterparty_deadline')
            ->get();

        $makeupEligible = ClassSession::query()
            ->where('status', 'completed')
            ->where('attendance_outcome', ClassSession::OUTCOME_TEACHER_ABSENT)
            ->whereNull('course_id')
            ->whereDoesntHave('makeupSessions')
            ->whereDoesntHave('rescheduleRequests', fn ($q) => $q->where('status', 'pending'))
            ->whereHas('booking', function (Builder $b) use ($studentId) {
                $b->whereHas('package', fn ($p) => $p->where('session_format', '!=', 'group'))
                    ->whereIn('status', ClassSession::PAID_BOOKING_STATUSES);
                if ($studentId) {
                    $b->where('student_id', $studentId);
                }
            })
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
            ->with(self::SESSION_RELATIONS)
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get();

        return $this->success([
            'count' => $awaiting->count() + $makeupEligible->count() + $awaitingPayment->count() + $awaitingConfirmation->count(),
            'awaiting_response' => $awaiting->map(fn (RescheduleRequest $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'requester_role' => $r->requester_role,
                'requester_name' => $r->requester?->name,
                'current_scheduled_at' => $this->iso($r->current_scheduled_at),
                'proposed_scheduled_at' => $this->iso($r->proposed_scheduled_at),
                'counterparty_deadline' => $this->iso($r->counterparty_deadline),
                'reason' => $r->reason,
                'session' => $this->sessionSummary($r->session, $user->isTeacher()),
            ])->values(),
            'makeup_eligible' => $makeupEligible->map(fn (ClassSession $s) => $this->sessionSummary($s, $user->isTeacher()))->values(),
            'awaiting_payment' => $awaitingPayment,
            'awaiting_confirmation' => $awaitingConfirmation,
        ]);
    }

    /**
     * طلبات حجز باقات المعلم الفردية بانتظار موافقته، الأقرب مهلةً أولاً. ما فات أقرب موعد فيه
     * يُستبعد هنا (باطل فعلاً) — تحويله expired شأن المجدول الدوري، لا هذه القراءة.
     */
    private function awaitingConfirmation(int $teacherId): \Illuminate\Support\Collection
    {
        return Booking::query()
            ->where('teacher_id', $teacherId)
            ->where('status', 'pending_teacher_confirmation')
            ->with(['student.user:id,name,avatar_path', 'package:id,title,subject_id', 'package.subject:id,name_ar'])
            ->get()
            ->map(fn (Booking $b) => ['booking' => $b, 'deadline' => $this->bookingService->requestedSlotAt($b)])
            ->filter(fn (array $row) => $row['deadline']->isFuture())
            ->sortBy(fn (array $row) => $row['deadline']->getTimestamp())
            ->map(fn (array $row) => [
                'id' => $row['booking']->id,
                'student_name' => $row['booking']->student?->user?->name,
                'student_avatar' => $row['booking']->student?->user?->avatar_path,
                'title' => $row['booking']->package?->title,
                'subject' => $row['booking']->package?->subject?->name_ar,
                'requested_slots' => $this->bookingService->requestedSlots($row['booking']),
                'created_at' => $this->iso($row['booking']->created_at),
                'deadline' => $this->iso($row['deadline']),
            ])
            ->values();
    }

    /**
     * حجوزات/تسجيلات الطالب بانتظار الدفع، الأقرب انتهاءً أولاً. المنقضية تُحوَّل expired أولاً
     * (نفس استدعاء قائمة الجلسات) كي لا تظهر هنا بعد ضياعها. بلا مهلة (null) = بلا انتهاء.
     */
    private function awaitingPayment(int $studentId): \Illuminate\Support\Collection
    {
        $this->bookingService->expireStalePendingPayments();

        $bookings = Booking::query()
            ->where('student_id', $studentId)
            ->where('status', 'pending_payment')
            ->with(['teacher.user:id,name,avatar_path', 'package:id,title,subject_id,audience', 'package.subject:id,name_ar'])
            ->get()
            ->map(fn (Booking $b) => [
                'kind' => 'booking',
                'id' => $b->id,
                'title' => $b->package?->title,
                'teacher_name' => $b->teacher?->user?->name,
                'teacher_avatar' => $b->teacher?->user?->avatar_path,
                'subject' => $b->package?->subject?->name_ar,
                'amount' => (float) $b->amount_paid,
                'discount_amount' => (float) $b->discount_amount,
                'has_coupon' => $b->coupon_id !== null,
                'is_campaign' => $b->package?->audience === 'campaign',
                'hold_expires_at' => $this->iso($b->hold_expires_at),
            ]);

        $enrollments = Enrollment::query()
            ->where('student_id', $studentId)
            ->where('status', 'pending_payment')
            ->with(['teacher.user:id,name,avatar_path', 'course:id,title,subject_id,course_field_id', 'course.subject:id,name_ar', 'course.courseField:id,name_ar'])
            ->get()
            ->map(fn (Enrollment $e) => [
                'kind' => 'enrollment',
                'id' => $e->id,
                'title' => $e->course?->title,
                'teacher_name' => $e->teacher?->user?->name,
                'teacher_avatar' => $e->teacher?->user?->avatar_path,
                'subject' => $e->course?->courseField?->name_ar ?? $e->course?->subject?->name_ar,
                'amount' => (float) $e->amount_paid,
                'discount_amount' => (float) $e->discount_amount,
                'has_coupon' => $e->coupon_id !== null,
                'is_campaign' => false,
                'hold_expires_at' => $this->iso($e->hold_expires_at),
            ]);

        return $bookings->concat($enrollments)
            ->sortBy(fn (array $item) => $item['hold_expires_at'] ?? '9999')
            ->values();
    }

    private const SESSION_RELATIONS = [
        'teacher.user:id,name',
        'booking:id,package_id,student_id,status',
        'booking.package:id,title,session_format',
        'booking.student.user:id,name',
    ];

    /** الطرف الآخر من منظور المستخدم: المعلم يرى اسم الطالب، والطالب يرى اسم المعلم */
    private function sessionSummary(?ClassSession $session, bool $forTeacher): ?array
    {
        if (! $session) {
            return null;
        }

        return [
            'id' => $session->id,
            'scheduled_at' => $this->iso($session->scheduled_at),
            'duration_min' => $session->duration_min,
            'title' => $session->booking?->package?->title,
            'other_party_name' => $forTeacher
                ? $session->booking?->student?->user?->name
                : $session->teacher?->user?->name,
        ];
    }

    private function iso(?Carbon $dateTime): ?string
    {
        return $dateTime?->toIso8601String();
    }
}
