<?php

namespace App\Http\Controllers\SupportPackage;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportPackage\CreateSupportPackageRequest;
use App\Http\Requests\SupportPackage\RejectSupportPackageRequest;
use App\Http\Requests\SupportPackage\UpdateSupportPackageRequest;
use App\Http\Resources\SupportPackage\SupportPackageResource;
use App\Models\SupportPackage;
use Illuminate\Http\Request;
use App\Services\SupportPackageService;

class SupportPackageController extends Controller
{
    public function __construct(private readonly SupportPackageService $supportPackageService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SupportPackage::class);

        $user = $request->user();

        $supportPackages = SupportPackage::query()
            ->with(['academicSupporter:id,user_id', 'academicSupporter.user:id,name', 'subjects:id,name_ar'])
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('academicSupporter', fn ($t) => $t->where('user_id', $user->id)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($supportPackages->through(fn (SupportPackage $sp) => new SupportPackageResource($sp)));
    }

    public function show(Request $request, SupportPackage $supportPackage)
    {
        $this->authorize('view', $supportPackage);

        $supportPackage->load(['academicSupporter.user:id,name', 'subjects:id,name_ar']);

        return $this->success(new SupportPackageResource($supportPackage));
    }

    public function store(CreateSupportPackageRequest $request)
    {
        $supporter = $request->user()->loadMissing('academicSupporter')->academicSupporter;

        $supportPackage = $this->supportPackageService->create($supporter, $request->validated());

        return $this->success(new SupportPackageResource($supportPackage), 'تم إرسال باقة الدعم بانتظار مراجعة الأدمن', 201);
    }

    public function update(UpdateSupportPackageRequest $request, SupportPackage $supportPackage)
    {
        $supportPackage = $this->supportPackageService->update($supportPackage, $request->validated());

        return $this->success(new SupportPackageResource($supportPackage), 'تم تعديل باقة الدعم');
    }

    public function approve(Request $request, SupportPackage $supportPackage)
    {
        $this->authorize('approve', SupportPackage::class);

        $supportPackage = $this->supportPackageService->approve($supportPackage, $request->user());

        return $this->success(new SupportPackageResource($supportPackage), 'تمت الموافقة على باقة الدعم');
    }

    public function reject(RejectSupportPackageRequest $request, SupportPackage $supportPackage)
    {
        $supportPackage = $this->supportPackageService->reject($supportPackage, $request->user(), $request->validated('reason'));

        return $this->success(new SupportPackageResource($supportPackage), 'تم رفض باقة الدعم');
    }

    public function disable(Request $request, SupportPackage $supportPackage)
    {
        $this->authorize('disable', $supportPackage);

        $supportPackage = $this->supportPackageService->disable($supportPackage);

        return $this->success(new SupportPackageResource($supportPackage), 'تم تعطيل باقة الدعم');
    }

    public function enable(Request $request, SupportPackage $supportPackage)
    {
        $this->authorize('enable', $supportPackage);

        $supportPackage = $this->supportPackageService->enable($supportPackage);

        return $this->success(new SupportPackageResource($supportPackage), 'تمت إعادة تفعيل باقة الدعم');
    }
}
