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
            ->with(['session:id,scheduled_at,teacher_id', 'requester:id,name'])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('requested_by', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

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
}
