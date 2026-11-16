<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\RescheduleRequest;
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
 */
class PendingActionsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $teacherId = $user->isTeacher() ? $user->loadMissing('teacher')->teacher?->id : null;
        $studentId = $user->isStudent() ? $user->loadMissing('student')->student?->id : null;

        if (! $teacherId && ! $studentId) {
            return $this->success(['count' => 0, 'awaiting_response' => [], 'makeup_eligible' => []]);
        }

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
                $b->whereHas('package', fn ($p) => $p->where('session_format', '!=', 'group'));
                if ($studentId) {
                    $b->where('student_id', $studentId)->whereIn('status', ClassSession::PAID_BOOKING_STATUSES);
                }
            })
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
            ->with(self::SESSION_RELATIONS)
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get();

        return $this->success([
            'count' => $awaiting->count() + $makeupEligible->count(),
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
        ]);
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
