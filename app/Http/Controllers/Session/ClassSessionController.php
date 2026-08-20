<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Http\Resources\Session\ClassSessionResource;
use App\Models\ClassSession;
use App\Services\SessionService;
use Illuminate\Http\Request;

class ClassSessionController extends Controller
{
    public function __construct(private readonly SessionService $sessionService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ClassSession::class);

        $sessions = ClassSession::query()
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($q) => $this->applyStatusFilter($q, $request->string('status')->value()))
            ->when(
                $request->filled('package_id'),
                fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('package_id', $request->integer('package_id')))
            )
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when(
                $request->filled('student_id'),
                fn ($q) => $q->whereHas('attendees', fn ($a) => $a->where('student_id', $request->integer('student_id')))
            )
            ->when($request->filled('from'), fn ($q) => $q->where('scheduled_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('scheduled_at', '<=', $request->date('to')))
            ->with(self::eagerLoad())
            // الافتراضي desc (الأحدث تاريخاً أولاً) يبقى كما كان لأي طرف آخر يعتمد
            // عليه ضمناً — sort=asc صريح (الأقرب موعداً أولاً) يطلبه استدعاء
            // "جلساتي" في لوحة الطالب تحديداً.
            ->when(
                $request->string('sort', 'desc')->value() === 'asc',
                fn ($q) => $q->orderBy('scheduled_at')->orderBy('id'),
                fn ($q) => $q->orderByDesc('scheduled_at')->orderByDesc('id'),
            )
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($sessions->through(fn (ClassSession $session) => new ClassSessionResource($session)));
    }

    public function show(ClassSession $session)
    {
        $this->authorize('view', $session);

        $session->load(self::eagerLoad());

        return $this->success(new ClassSessionResource($session));
    }

    /**
     * الفرونت إند يعرض 4 خيارات تصفية بحالة مبسّطة (upcoming/attended/cancelled/
     * reschedule_pending) تطابق حرفياً تجميع mapSessionStatus() في dashboard
     * services/index.js — وليست القيم الخام لعمود status (scheduled/completed/
     * cancelled/active/...). كان يُرسَل المفتاح المبسّط مباشرة كـ where('status', ...)
     * حرفياً، فلا توجد أبداً جلسة بقيمة status='upcoming' أو 'attended' فعلياً في
     * القاعدة — فيرجع الفلتر دائماً صفر نتائج لهما، وبصمت تام (بلا أي خطأ). هذه
     * الدالة تترجم كل حالة مبسّطة لمجموعة القيم الخام المطابقة لها؛ أي قيمة أخرى
     * غير هذه الأربع (مثال: status=completed مباشرة من مستهلك آخر للـ API) تُطابَق
     * حرفياً كما كانت — لا تغيير في ذلك السلوك.
     */
    private function applyStatusFilter($query, string $status)
    {
        $bucket = match ($status) {
            'upcoming' => ['scheduled', 'reschedule_pending', 'rescheduled', 'active', 'suspended'],
            'attended' => ['completed'],
            'cancelled' => ['cancelled', 'no_show_student', 'no_show_teacher'],
            default => null,
        };

        return $bucket ? $query->whereIn('status', $bucket) : $query->where('status', $status);
    }

    private static function eagerLoad(): array
    {
        return [
            'teacher:id,user_id',
            'teacher.user:id,name,avatar_path',
            'attendees:id,class_session_id,student_id,attendance',
            'attendees.student:id,user_id',
            'attendees.student.user:id,name',
            'rescheduleRequests' => fn ($q) => $q->select('id', 'class_session_id', 'requested_by', 'status', 'created_at')
                ->where('status', 'pending')
                ->latest(),
            'booking:id,package_id,student_id',
            'booking.package:id,title,session_format,subject_id',
            'booking.package.subject:id,name_ar',
            'course:id,title,subject_id',
            'course.subject:id,name_ar',
        ];
    }

    /**
     * يتحقق أن غرفة BBB تعمل فعلاً قبل إرجاع رابط الانضمام — بدل فتح رابط
     * BBB الخام مباشرة من الفرونت إند، الذي يُظهر استجابة XML غير منسّقة
     * حين يفشل (الجلسة لم تبدأ بعد، انتهت، أو لم تُنشأ الغرفة أصلاً).
     */
    public function join(Request $request, ClassSession $session)
    {
        $this->authorize('view', $session);

        $result = $this->sessionService->resolveJoinUrl($session, $request->user());

        if (! $result['joinable']) {
            return $this->error($result['message'], 422);
        }

        return $this->success(['url' => $result['url']]);
    }
}
