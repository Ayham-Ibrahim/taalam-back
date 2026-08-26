<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\AcceptInvitationRequest;
use App\Http\Requests\Teacher\CreateTeacherAccountRequest;
use App\Http\Requests\Teacher\InviteTeacherRequest;
use App\Http\Requests\Teacher\RejectTeacherRequest;
use App\Http\Requests\Teacher\SuspendTeacherRequest;
use App\Http\Requests\Teacher\UpdateTeacherProfileRequest;
use App\Http\Resources\Teacher\AdminTeacherResource;
use App\Http\Resources\Teacher\PublicTeacherResource;
use App\Http\Resources\Verification\BadgeAwardResource;
use App\Http\Resources\Verification\BadgeResource;
use App\Http\Resources\Verification\VerificationDocumentResource;
use App\Models\Badge;
use App\Models\Teacher;
use App\Services\TeacherService;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function __construct(private readonly TeacherService $teacherService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Teacher::class);

        $teachers = Teacher::query()
            ->with('user:id,name,email,phone')
            ->when($request->filled('status'), fn ($q) => $this->applyStatusFilter($q, $request->string('status')->value()))
            ->when($request->filled('teacher_type'), fn ($q) => $q->where('teacher_type', $request->string('teacher_type')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($teachers->through(fn (Teacher $teacher) => new AdminTeacherResource($teacher)));
    }

    /**
     * الفرونت إند يعرض 4 حالات مبسّطة فقط (pending/verified/rejected/suspended)
     * تطابق AdminTeacherResource::displayStatus() تماماً — وليست القيم الخام
     * الخمس لعمود teachers.status. كان يُرسَل المفتاح المبسّط مباشرة كـ
     * where('status', ...) حرفياً، فلا يوجد أبداً صف بقيمة status='pending'
     * فعلياً في القاعدة (الخام هو active_unverified أو pending_verification) —
     * فيرجع فلتر "بانتظار المراجعة" صفر نتائج دائماً وبصمت تام (بلا أي خطأ).
     * نفس عطل ClassSessionController::applyStatusFilter السابق، بنفس الحل هنا.
     */
    private function applyStatusFilter($query, string $status)
    {
        if ($status === 'pending') {
            return $query->whereIn('status', ['active_unverified', 'pending_verification']);
        }

        return $query->where('status', $status);
    }

    public function store(InviteTeacherRequest $request)
    {
        $teacher = $this->teacherService->invite($request->validated(), $request->user());

        return $this->success($teacher, 'تم إرسال دعوة للمعلم بنجاح', 201);
    }

    /** بديل عن store/invite — الأدمن يضع كلمة المرور مباشرة بدل رابط دعوة (انظر TeacherService::createByAdmin) */
    public function createAccount(CreateTeacherAccountRequest $request)
    {
        $teacher = $this->teacherService->createByAdmin($request->validated(), $request->user());

        return $this->success($teacher, 'تم إنشاء حساب المعلم بنجاح', 201);
    }

    public function acceptInvitation(AcceptInvitationRequest $request)
    {
        $result = $this->teacherService->acceptInvitation(
            $request->validated('token'),
            $request->validated('password'),
        );

        return $this->success($result, 'تم تفعيل الحساب بنجاح');
    }

    /**
     * عام بلا مصادقة عمداً (تصفح ملف معلم/مركز من صفحة البحث) — لذا لا نعتمد على
     * middleware auth:sanctum لحل المستخدم؛ نطلبه صراحةً كي يعمل للزائر وللمسجَّل
     * دخوله معاً. الزائر وأي مستخدم غير مالك/أدمن يريان فقط PublicTeacherResource
     * لمعلم موثّق؛ غير الموثّق غير موجود من منظورهما (404).
     */
    public function show(Request $request, Teacher $teacher)
    {
        $user = $request->user('sanctum');
        $isManager = $user && ($user->isAdmin() || $user->id === $teacher->user_id);

        if (! $isManager && ! $teacher->isVerified()) {
            abort(404);
        }

        $teacher->load(['user:id,name,avatar_path', 'subjects', 'curricula', 'languages']);
        if ($isManager) {
            $teacher->load(['user:id,name,email,phone,avatar_path', 'verificationDocuments']);
        }

        $teacher->setAttribute('stats', $this->teacherService->getStats($teacher));

        return $this->success($isManager ? $teacher : new PublicTeacherResource($teacher));
    }

    /**
     * لوحة إدارة الأدمن لتفاصيل معلم/مركز: شكل مُجمَّع مخصص (لا يشارك المسار
     * العام أعلاه، حتى لا يكسر teacherService.getTeacherById الذي يعتمد على
     * نموذج Teacher الخام لنفس المسار عند مشاهدة الأدمن/صاحب الحساب لملفه العام).
     */
    public function adminDetail(Teacher $teacher)
    {
        $this->authorize('view', $teacher);

        $teacher->load([
            'user:id,name,email,phone,avatar_path',
            'verificationDocuments',
            'badgeAwards.badge',
            'subjects',
            'curricula',
            'languages',
        ]);

        return $this->success([
            'teacher' => new AdminTeacherResource($teacher),
            'documents' => VerificationDocumentResource::collection($teacher->verificationDocuments),
            'badgeAwards' => BadgeAwardResource::collection($teacher->badgeAwards->whereNull('revoked_at')),
            'badgeCatalog' => BadgeResource::collection(Badge::query()->orderBy('sort_order')->get()),
        ]);
    }

    public function update(UpdateTeacherProfileRequest $request, Teacher $teacher)
    {
        $teacher = $this->teacherService->updateProfile($teacher, $request->validated());

        return $this->success($teacher, 'تم تحديث الملف الشخصي بنجاح');
    }

    public function submitForVerification(Teacher $teacher)
    {
        $this->authorize('submitForVerification', $teacher);

        $teacher = $this->teacherService->submitForVerification($teacher);

        return $this->success($teacher, 'تم إرسال طلب التوثيق للمراجعة');
    }

    public function approve(Request $request, Teacher $teacher)
    {
        $this->authorize('approve', $teacher);

        $teacher = $this->teacherService->approve($teacher, $request->user());

        return $this->success($teacher, 'تم توثيق حساب المعلم بنجاح');
    }

    public function reject(RejectTeacherRequest $request, Teacher $teacher)
    {
        $teacher = $this->teacherService->reject($teacher, $request->user(), $request->validated('reason'));

        return $this->success($teacher, 'تم رفض طلب التوثيق');
    }

    public function suspend(SuspendTeacherRequest $request, Teacher $teacher)
    {
        $teacher = $this->teacherService->suspend($teacher, $request->user(), $request->validated('reason'));

        return $this->success($teacher, 'تم تعليق حساب المعلم');
    }

    public function reactivate(Request $request, Teacher $teacher)
    {
        $this->authorize('reactivate', $teacher);

        $teacher = $this->teacherService->reactivate($teacher, $request->user());

        return $this->success($teacher, 'تمت إعادة تفعيل حساب المعلم بنجاح');
    }
}
