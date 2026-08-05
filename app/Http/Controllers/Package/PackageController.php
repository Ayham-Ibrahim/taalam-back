<?php

namespace App\Http\Controllers\Package;

use App\Http\Controllers\Controller;
use App\Http\Requests\Package\ApprovePackageRequest;
use App\Http\Requests\Package\CreatePackageRequest;
use App\Http\Requests\Package\RejectPackageRequest;
use App\Http\Requests\Package\UpdatePackageRequest;
use App\Http\Resources\Package\AdminPackageResource;
use App\Http\Resources\Package\StudentPackageResource;
use App\Http\Resources\Package\TeacherPackageResource;
use App\Models\Package;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PackageApprovalService;
use App\Services\PackageService;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function __construct(
        private readonly PackageService $packageService,
        private readonly PackageApprovalService $approvalService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Package::class);

        $user = $request->user();

        $packages = Package::query()
            ->select([
                'id', 'teacher_id', 'title', 'subject_id', 'session_format', 'capacity',
                'enrolled_count', 'sessions_count',
                'teacher_price', 'platform_margin_percent', 'student_price', 'platform_revenue',
                'currency', 'discount_percent', 'status', 'submitted_at', 'approved_at',
                'approved_by', 'rejection_reason', 'created_at',
            ])
            ->with(['teacher:id,user_id,teacher_type', 'teacher.user:id,name', 'subject:id,name_ar', 'schedules'])
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('teacher', fn ($t) => $t->where('user_id', $user->id)))
            ->when(
                $user->isAdmin() && $request->filled('owner'),
                fn ($q) => $q->where('teacher_id', $request->integer('owner'))
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($packages->through(fn (Package $package) => $this->resourceFor($package, $user)));
    }

    public function store(CreatePackageRequest $request)
    {
        $teacher = $request->user()->loadMissing('teacher')->teacher;

        $package = $this->packageService->createDraft($teacher, $request->validated());

        return $this->success($this->resourceFor($package, $request->user()), 'تم إنشاء مسودة الباقة بنجاح', 201);
    }

    /**
     * تصفح عام لباقات معلم موثّق النشطة فقط (للحجز) — بيانات غير حساسة، بلا Policy
     * خاص، بنفس منطق TeacherSearchController العام. المعلم غير الموثّق لا تظهر باقاته.
     */
    public function indexForTeacher(Teacher $teacher)
    {
        abort_unless($teacher->isVerified(), 404);

        $packages = Package::query()
            ->where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->with(['subject:id,name_ar', 'schedules'])
            ->latest()
            ->paginate(20);

        return $this->paginate($packages->through(fn (Package $package) => new StudentPackageResource($package)));
    }

    public function show(Request $request, Package $package)
    {
        $this->authorize('view', $package);

        $package->load(['curricula', 'stages', 'schedules', 'teacher:id,user_id,teacher_type', 'teacher.user:id,name', 'subject:id,name_ar']);

        return $this->success($this->resourceFor($package, $request->user()));
    }

    public function update(UpdatePackageRequest $request, Package $package)
    {
        $package = $this->packageService->updateDraft($package, $request->validated());

        return $this->success($this->resourceFor($package, $request->user()), 'تم تحديث الباقة بنجاح');
    }

    public function submit(Request $request, Package $package)
    {
        $this->authorize('submit', $package);

        $package = $this->packageService->submitForApproval($package);

        return $this->success($this->resourceFor($package, $request->user()), 'تم إرسال الباقة للمراجعة');
    }

    public function disable(Request $request, Package $package)
    {
        $this->authorize('disable', $package);

        $package = $this->packageService->disable($package);

        return $this->success($this->resourceFor($package, $request->user()), 'تم تعطيل الباقة');
    }

    public function approve(ApprovePackageRequest $request, Package $package)
    {
        $package = $this->approvalService->approve(
            $package,
            $request->user(),
            (float) $request->validated('platform_margin_percent'),
        );

        return $this->success($this->resourceFor($package, $request->user()), 'تمت الموافقة على الباقة');
    }

    public function reject(RejectPackageRequest $request, Package $package)
    {
        $package = $this->approvalService->reject($package, $request->user(), $request->validated('reason'));

        return $this->success($this->resourceFor($package, $request->user()), 'تم رفض الباقة');
    }

    private function resourceFor(Package $package, User $user): AdminPackageResource|TeacherPackageResource|StudentPackageResource
    {
        if ($user->isAdmin()) {
            return new AdminPackageResource($package);
        }

        if ($user->loadMissing('teacher')->teacher?->id === $package->teacher_id) {
            return new TeacherPackageResource($package);
        }

        return new StudentPackageResource($package);
    }
}
