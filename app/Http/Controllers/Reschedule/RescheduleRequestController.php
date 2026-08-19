<?php

namespace App\Http\Controllers\Reschedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reschedule\ApproveRescheduleRequestRequest;
use App\Http\Requests\Reschedule\CreateRescheduleRequestRequest;
use App\Http\Requests\Reschedule\RejectRescheduleRequestRequest;
use App\Models\ClassSession;
use App\Models\RescheduleRequest;
use App\Services\RescheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RescheduleRequestController extends Controller
{
    public function __construct(private readonly RescheduleService $rescheduleService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', RescheduleRequest::class);

        $user = $request->user();

        $requests = RescheduleRequest::query()
            ->with([
                'requester:id,name',
                'booking.student.user:id,name',
                'session:id,teacher_id,booking_id',
                'session.teacher.user:id,name',
                'session.attendees:id,class_session_id,student_id',
                'session.attendees.student.user:id,name',
            ])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('requested_by', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        $requests->setCollection(
            $requests->getCollection()->map(fn (RescheduleRequest $rescheduleRequest) => $this->serializeListItem($rescheduleRequest))
        );

        return $this->paginate($requests);
    }

    public function show(Request $request, RescheduleRequest $rescheduleRequest)
    {
        $this->authorize('view', $rescheduleRequest);

        return $this->success($rescheduleRequest->load(['session', 'requester:id,name', 'reviewer:id,name']));
    }

    public function store(CreateRescheduleRequestRequest $request, ClassSession $session)
    {
        $user = $request->user();
        $role = $user->isStudent() ? 'student' : 'teacher';

        $rescheduleRequest = $this->rescheduleService->request(
            $session,
            $user,
            $role,
            Carbon::parse($request->validated('proposed_scheduled_at')),
            $request->validated('reason'),
        );

        return $this->success($rescheduleRequest, 'تم إرسال طلب تغيير الموعد بانتظار مراجعة الأدمن', 201);
    }

    public function approve(ApproveRescheduleRequestRequest $request, RescheduleRequest $rescheduleRequest)
    {
        $alternative = $request->validated('alternative_scheduled_at')
            ? Carbon::parse($request->validated('alternative_scheduled_at'))
            : null;

        $rescheduleRequest = $this->rescheduleService->approve(
            $rescheduleRequest,
            $request->user(),
            $alternative,
            $request->validated('notes'),
        );

        return $this->success($rescheduleRequest, 'تمت الموافقة على طلب تغيير الموعد');
    }

    public function reject(RejectRescheduleRequestRequest $request, RescheduleRequest $rescheduleRequest)
    {
        $rescheduleRequest = $this->rescheduleService->reject($rescheduleRequest, $request->user(), $request->validated('reason'));

        return $this->success($rescheduleRequest, 'تم رفض طلب تغيير الموعد');
    }

    private function serializeListItem(RescheduleRequest $rescheduleRequest): array
    {
        $teacherName = $rescheduleRequest->session?->teacher?->user?->name
            ?? ($rescheduleRequest->requester_role === 'teacher' ? $rescheduleRequest->requester?->name : null);

        $studentName = $this->resolveStudentName($rescheduleRequest);

        return [
            'id' => $rescheduleRequest->id,
            'teacherName' => $teacherName,
            'studentName' => $studentName,
            'requesterRole' => $rescheduleRequest->requester_role,
            'status' => $rescheduleRequest->status,
            'reason' => $rescheduleRequest->reason,
            'rejectionReason' => $rescheduleRequest->rejection_reason,
            'withinFreeWindow' => $rescheduleRequest->within_free_window,
            'originalScheduledAt' => $this->toIso($rescheduleRequest->current_scheduled_at),
            'proposedScheduledAt' => $this->toIso($rescheduleRequest->proposed_scheduled_at),
            'alternativeScheduledAt' => $this->toIso($rescheduleRequest->admin_alternative_at),
            'reviewedAt' => $this->toIso($rescheduleRequest->reviewed_at),
            'slaDueAt' => $this->toIso($rescheduleRequest->sla_due_at),
            'createdAt' => $this->toIso($rescheduleRequest->created_at),
        ];
    }

    private function resolveStudentName(RescheduleRequest $rescheduleRequest): ?string
    {
        if ($rescheduleRequest->requester_role === 'student') {
            return $rescheduleRequest->requester?->name
                ?? $rescheduleRequest->booking?->student?->user?->name
                ?? $rescheduleRequest->session?->attendees->first()?->student?->user?->name;
        }

        $bookingStudentName = $rescheduleRequest->booking?->student?->user?->name;
        if ($bookingStudentName) {
            return $bookingStudentName;
        }

        $attendeeCount = $rescheduleRequest->session?->attendees?->count() ?? 0;
        if ($attendeeCount > 1) {
            return sprintf('مجموعة (%d طلاب)', $attendeeCount);
        }

        return $rescheduleRequest->session?->attendees->first()?->student?->user?->name;
    }

    private function toIso(?Carbon $dateTime): ?string
    {
        return $dateTime?->toIso8601String();
    }
}
