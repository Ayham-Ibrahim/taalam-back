<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\SupportPackage;
use App\Models\Teacher;
use App\Services\AcademicSupporterService;
use Illuminate\Http\Request;

/**
 * باقات الدعم الأكاديمي للمدرس الجامعي:
 * - store: الأدمن يفعّلها له — يُنشئ (أو يعيد) ملف الداعم المرتبط به، ثم تُدار باقاته بنفس
 *   شاشات/مسارات باقات الدعم الموجودة (academic_supporter_id).
 * - index: عرض عام بلا auth لبروفايل المدرس العام (كبقية باقاته) — الشراء نفسه يبقى للطالب
 *   الجامعي فقط (SupportPackagePurchaseService يتحقق من ذلك).
 */
class TeacherAcademicSupportController extends Controller
{
    public function __construct(private readonly AcademicSupporterService $supporterService) {}

    public function index(Teacher $teacher)
    {
        // نفس شرط ظهور باقات المدرس العادية: مدرس جامعي موثّق، وملف داعمه نشط
        $supporter = $teacher->teacher_type === 'university' && $teacher->isVerified()
            ? $teacher->academicSupporter()->where('status', 'active')->first()
            : null;

        $packages = $supporter
            ? $supporter->supportPackages()->where('status', 'active')->with('subjects:id,name_ar')->latest()->get()
            : collect();

        return $this->success($packages->map(fn (SupportPackage $sp) => [
            'id' => $sp->id,
            'academicSupporterId' => $sp->academic_supporter_id,
            'title' => $sp->title,
            'tier' => $sp->tier,
            'description' => $sp->description,
            'subjects' => $sp->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar])->values(),
            'price' => (float) $sp->student_price,
            'currency' => $sp->currency,
        ])->values());
    }

    public function store(Request $request, Teacher $teacher)
    {
        // إنشاء باقات الدعم للأدمن حصراً — نفس صلاحية إنشاء الباقة نفسها
        $this->authorize('create', SupportPackage::class);

        $supporter = $this->supporterService->linkUniversityTeacher($teacher, $request->user());

        return $this->success(['academic_supporter_id' => $supporter->id], 'تم تفعيل باقات الدعم الأكاديمي لهذا المدرس');
    }
}
