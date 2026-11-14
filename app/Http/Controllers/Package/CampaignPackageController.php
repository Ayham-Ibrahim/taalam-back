<?php

namespace App\Http\Controllers\Package;

use App\Http\Controllers\Controller;
use App\Http\Requests\Package\CreateCampaignPackageRequest;
use App\Http\Resources\Package\AdminPackageResource;
use App\Http\Resources\Package\StudentPackageResource;
use App\Models\Package;
use App\Models\Teacher;
use App\Services\BookingService;
use App\Services\PackageService;
use Illuminate\Http\Request;

/**
 * باقات الحملة المخفضة: الأدمن ينشئها باسم معلم، وحسابات الحملة (ولو بعد تحويلها) تتصفحها.
 * تعطيلها وتفعيلها عبر مسارات الباقات العادية (packages/{id}/disable|enable) للأدمن وحده.
 */
class CampaignPackageController extends Controller
{
    public function __construct(
        private readonly PackageService $packageService,
        private readonly BookingService $bookingService,
    ) {}

    /** right_used: استخدم الحساب حقه في باقة واحدة من باقات الحملة (طلب قائم أو حجز) */
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless($user->isFromCampaign(), 403, 'باقات الحملة متاحة لحسابات الحملة فقط');

        $packages = Package::query()
            ->campaignAudience()
            ->bookable()
            ->whereHas('teacher', fn ($q) => $q->where('status', 'verified'))
            ->with(['subject:id,name_ar', 'curricula:id,name_ar', 'stages', 'schedules', 'teacher:id,user_id', 'teacher.user:id,name,avatar_path'])
            ->latest()
            ->get();

        $student = $user->loadMissing('student')->student;

        return $this->success([
            'right_used' => $student ? $this->bookingService->campaignRightUsed($student) : false,
            'packages' => $packages->map(fn (Package $package) => [
                ...(new StudentPackageResource($package))->resolve($request),
                'teacher' => [
                    'id' => $package->teacher->id,
                    'name' => $package->teacher->user?->name,
                    'avatar_path' => $package->teacher->user?->avatar_path,
                ],
            ])->values(),
        ]);
    }

    public function store(CreateCampaignPackageRequest $request)
    {
        $teacher = Teacher::findOrFail($request->validated('teacher_id'));

        $package = $this->packageService->createCampaignPackage($teacher, $request->user(), $request->validated());

        return $this->success(new AdminPackageResource($package), 'تم إنشاء باقة الحملة وإبلاغ المعلم', 201);
    }
}
