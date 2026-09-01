<?php

namespace App\Http\Controllers\Complaint;

use App\Http\Controllers\Controller;
use App\Http\Requests\Complaint\FileComplaintRequest;
use App\Http\Requests\Complaint\ResolveComplaintRequest;
use App\Models\Complaint;
use App\Services\ComplaintService;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintService $complaintService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Complaint::class);

        $user = $request->user()->loadMissing(['student', 'teacher']);

        $complaints = Complaint::query()
            ->with(['student.user:id,name', 'teacher.user:id,name', 'booking.package.subject', 'session.booking.package.subject'])
            ->when(! $user->isAdmin(), function ($q) use ($user) {
                $q->where(function ($q) use ($user) {
                    $q->where('student_id', $user->student?->id ?? 0)
                        ->orWhere('teacher_id', $user->teacher?->id ?? 0);
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($complaints);
    }

    public function show(Request $request, Complaint $complaint)
    {
        $this->authorize('view', $complaint);

        return $this->success($complaint->load([
            'student.user:id,name',
            'teacher.user:id,name',
            'booking:id,reference',
            'session:id,scheduled_at',
            'resolver:id,name',
        ]));
    }

    public function store(FileComplaintRequest $request)
    {
        $user = $request->user()->loadMissing(['student', 'teacher']);

        $complaint = $this->complaintService->file($user->student, $user->teacher, $request->validated());

        return $this->success($complaint, 'تم تسجيل الشكوى بنجاح', 201);
    }

    public function resolve(ResolveComplaintRequest $request, Complaint $complaint)
    {
        $complaint = $this->complaintService->resolve(
            $complaint,
            $request->user(),
            $request->validated('resolution_type'),
            $request->validated('notes'),
        );

        return $this->success($complaint, 'تم حل الشكوى');
    }

    public function escalate(Request $request, Complaint $complaint)
    {
        $this->authorize('escalate', Complaint::class);

        $complaint = $this->complaintService->escalate($complaint);

        return $this->success($complaint, 'تم تصعيد الشكوى');
    }
}
