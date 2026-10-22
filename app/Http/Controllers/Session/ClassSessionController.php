<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\Session\RescheduleSessionRequest;
use App\Http\Resources\Session\ClassSessionResource;
use App\Models\ClassSession;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RescheduleService;
use App\Services\SessionJoinLogService;
use App\Services\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class ClassSessionController extends Controller
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly BookingService $bookingService,
        private readonly RescheduleService $rescheduleService,
        private readonly SessionJoinLogService $joinLogService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ClassSession::class);

        // الجلسات تُنشأ فور موافقة المعلم بصرف النظر عن الدفع، فحجز انقضت مهلته
        // المؤقتة دون دفع تبقى جلساته "قادمة" ظاهرةً للطالب حتى يلحق المجدول
        // الدوري بتحويله إلى expired. نحوّله كسْلاً هنا أولاً (نفس نمط
        // BookingController) كي يُخفيه فلتر visibleTo فوراً، لا بعد دقيقة.
        $this->bookingService->expireStalePendingPayments();

        $sessions = ClassSession::query()
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($q) => $this->applyStatusFilter($q, $request->string('status')->value()))
            ->when($request->filled('category'), fn ($q) => $this->applyCategoryFilter($q, $request->string('category')->value()))
            ->when(
                $request->filled('package_id'),
                fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('package_id', $request->integer('package_id')))
            )
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            // تبويب "الجلسات" في ملف المعلم لدى الأدمن — للمعلم نفسه لا أثر له (visibleTo يقيّده بجلساته أصلاً).
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when(
                $request->filled('student_id'),
                fn ($q) => $q->whereHas('attendees', fn ($a) => $a->where('student_id', $request->integer('student_id')))
            )
            ->when($request->filled('from'), fn ($q) => $q->where('scheduled_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('scheduled_at', '<=', $request->date('to')))
            ->with(self::eagerLoad())
            ->withStudentPaidAccess($request->user())
            ->when($request->user()->isAdmin(), fn ($q) => $q->with(self::JOIN_LOGS_RELATION))
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

        if (request()->user()->isAdmin()) {
            $session->load(self::JOIN_LOGS_RELATION);
        }

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
     *
     * reschedule_pending تحديداً ليست قيمة تُخزَّن فعلياً في class_sessions.status
     * إطلاقاً (لا مكان في الكود يضبطها) رغم وجودها ضمن enum العمود — هي حالة
     * مُشتقّة بالكامل من وجود RescheduleRequest بحالة pending مرتبط بالجلسة (نفس
     * منطق hasPendingRescheduleRequest() في الفرونت إند)، فتُرجم لـ whereHas لا
     * لقيمة عمود حرفية — وإلا تُرجع صفر نتائج دائماً بصمت مطابقةً لنفس عطل
     * upcoming/attended قبل هذا الإصلاح.
     */
    private function applyStatusFilter($query, string $status)
    {
        if ($status === 'reschedule_pending') {
            return $query->whereHas('rescheduleRequests', fn ($q) => $q->where('status', 'pending'));
        }

        $bucket = match ($status) {
            'upcoming' => ['scheduled', 'reschedule_pending', 'rescheduled', 'active', 'suspended'],
            'attended' => ['completed'],
            'cancelled' => ['cancelled', 'no_show_student', 'no_show_teacher'],
            default => null,
        };

        return $bucket ? $query->whereIn('status', $bucket) : $query->where('status', $status);
    }

    /**
     * الفرونت إند يعرض تبويب فئة مبسّط (all/individual/group/training) يطابق
     * sessionCategory() في dashboard services/index.js حرفياً — لا يوجد عمود
     * category خام في class_sessions أصلاً؛ الفئة مُشتقّة من course_id (تدريب)
     * أو session_format باقة الحجز (فردي/جماعي). كانت هذه الفئة تُصفّى بالكامل
     * على الفرونت إند بعد أن يُرجِع السيرفر صفحة مُرقَّمة سلفاً من كل الفئات
     * مجتمعة — فتصبح صفحة بعينها فارغة تماماً من الفئة المختارة (مثال: "جماعي")
     * بينما يبقى شريط الترقيم وعدّاد الإجمالي ظاهرَين بقيمة إجمالي كل الفئات
     * (61 جلسة مثلاً)، موحيَين بوجود بيانات لا علاقة لها بالفئة المختارة فعلاً.
     */
    private function applyCategoryFilter($query, string $category)
    {
        if ($category === 'all') {
            return $query;
        }

        if ($category === 'training') {
            return $query->whereNotNull('course_id');
        }

        return $query->whereNull('course_id')
            ->whereHas('booking.package', fn ($q) => $q->where('session_format', $category));
    }

    /** سجل الانضمام يظهر للأدمن فقط — لا يُحمَّل (فلا يظهر في الاستجابة) لغيره */
    private const JOIN_LOGS_RELATION = 'joinLogs:id,class_session_id,user_id,role,student_id,first_joined_at,last_joined_at,join_count';

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
            'booking:id,package_id,student_id,status',
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
        $this->authorize('join', $session);

        $result = $this->sessionService->resolveJoinUrl($session, $request->user());

        if (! $result['joinable']) {
            return $this->error($result['message'], 422);
        }

        $this->joinLogService->record($session, $request->user());

        return $this->success(['url' => $result['url']]);
    }

    /**
     * زر بريد التذكير (رابط موقّع لمستلم بعينه) — نفس منطق join تماماً لكن بتحويل
     * مباشر لغرفة BBB، فيُسجَّل انضمام المستلم كأنه ضغط "انضم" من المنصة.
     */
    public function joinRedirect(ClassSession $session, User $user)
    {
        // رابط بريد قديم لحجز بطل بعد إرساله (انتهت مهلته/أُلغي) — صفحة واضحة بدل 403 خام
        if (Gate::forUser($user)->denies('join', $session)) {
            return response()->view('session-join-unavailable', [
                'message' => 'لا يمكنك الانضمام لهذه الجلسة — اشتراكك فيها غير مدفوع أو لم يعد قائماً.',
                'platformUrl' => frontend_url('/login'),
            ], 403);
        }

        $result = $this->sessionService->resolveJoinUrl($session, $user);

        if (! $result['joinable']) {
            return response()->view('session-join-unavailable', [
                'message' => $result['message'],
                'platformUrl' => frontend_url('/login'),
            ], 422);
        }

        $this->joinLogService->record($session, $user);

        return redirect()->away($result['url']);
    }

    /**
     * تعديل الأدمن لموعد الجلسة مباشرة — رابط الانضمام يبقى كما هو. القيود
     * (جلسة لم تبدأ، موعد مستقبلي، لا حضور مسجَّل، لا تعارض) في
     * RescheduleService::adminReschedule.
     */
    public function reschedule(RescheduleSessionRequest $request, ClassSession $session)
    {
        // الموعد يُخزَّن بتوقيت التطبيق (UTC) — نصّ ISO بإزاحة توقيت (+03:00)
        // يُحوَّل هنا صراحة، وإلا يُحفَظ رقم الساعة المحلي كما هو على أنه UTC.
        $newTime = Carbon::parse($request->validated('scheduled_at'))->setTimezone(config('app.timezone'));

        $session = $this->rescheduleService->adminReschedule($session, $request->user(), $newTime, $request->validated('notes'));

        $session->load(self::eagerLoad());

        return $this->success(new ClassSessionResource($session), 'تم تعديل موعد الجلسة');
    }
}
