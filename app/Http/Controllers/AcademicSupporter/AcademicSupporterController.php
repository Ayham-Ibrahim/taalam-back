<?php

namespace App\Http\Controllers\AcademicSupporter;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicSupporter\AdminResetAcademicSupporterPasswordRequest;
use App\Http\Requests\AcademicSupporter\CreateAcademicSupporterAccountRequest;
use App\Http\Requests\AcademicSupporter\SuspendAcademicSupporterRequest;
use App\Http\Requests\AcademicSupporter\UpdateAcademicSupporterProfileRequest;
use App\Http\Resources\AcademicSupporter\AdminAcademicSupporterResource;
use App\Models\AcademicSupporter;
use App\Services\AcademicSupporterService;
use Illuminate\Http\Request;

/**
 * قسم إدارة الدعم الأكاديمي — منفصل تماماً عن TeacherController/إدارة المعلمين
 * (قرار صريح: الداعم الأكاديمي كيان مستقل لا نوع معلم). لا مرحلة توثيق هنا —
 * الحساب نشط فوراً عند الإنشاء (AcademicSupporterService::createByAdmin).
 */
class AcademicSupporterController extends Controller
{
    public function __construct(private readonly AcademicSupporterService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', AcademicSupporter::class);

        $supporters = AcademicSupporter::query()
            ->with('user:id,name,email,phone')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->whereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($supporters->through(fn (AcademicSupporter $s) => new AdminAcademicSupporterResource($s)));
    }

    public function show(Request $request, AcademicSupporter $academicSupporter)
    {
        $this->authorize('view', $academicSupporter);

        $academicSupporter->load([
            'user:id,name,email,phone,avatar_path',
            'subjects', 'languages', 'galleryItems',
            'supportPackages' => fn ($q) => $q->with('subject:id,name_ar')->latest(),
        ]);

        return $this->success(new AdminAcademicSupporterResource($academicSupporter));
    }

    public function createAccount(CreateAcademicSupporterAccountRequest $request)
    {
        $supporter = $this->service->createByAdmin($request->validated(), $request->user());

        return $this->success($supporter, 'تم إنشاء حساب الداعم الأكاديمي بنجاح', 201);
    }

    public function update(UpdateAcademicSupporterProfileRequest $request, AcademicSupporter $academicSupporter)
    {
        $academicSupporter = $this->service->updateProfile($academicSupporter, $request->validated());

        return $this->success($academicSupporter, 'تم تحديث الملف الشخصي بنجاح');
    }

    public function resetPassword(AdminResetAcademicSupporterPasswordRequest $request, AcademicSupporter $academicSupporter)
    {
        $this->service->resetPasswordByAdmin($academicSupporter, $request->validated('password'), $request->user());

        return $this->success(null, 'تم تغيير كلمة مرور الداعم الأكاديمي بنجاح، وأُرسلت له عبر البريد الإلكتروني');
    }

    public function suspend(SuspendAcademicSupporterRequest $request, AcademicSupporter $academicSupporter)
    {
        $academicSupporter = $this->service->suspend($academicSupporter, $request->user(), $request->validated('reason'));

        return $this->success($academicSupporter, 'تم تعليق حساب الداعم الأكاديمي');
    }

    public function reactivate(Request $request, AcademicSupporter $academicSupporter)
    {
        $this->authorize('reactivate', AcademicSupporter::class);

        $academicSupporter = $this->service->reactivate($academicSupporter, $request->user());

        return $this->success($academicSupporter, 'تمت إعادة تفعيل حساب الداعم الأكاديمي بنجاح');
    }

    public function destroy(Request $request, AcademicSupporter $academicSupporter)
    {
        $this->authorize('delete', AcademicSupporter::class);

        $this->service->deleteAcademicSupporter($academicSupporter, $request->user());

        return $this->success(null, 'تم حذف حساب الداعم الأكاديمي بنجاح');
    }
}
