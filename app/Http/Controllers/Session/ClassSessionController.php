<?php

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\Session\CreateMakeupSessionRequest;
use App\Http\Requests\Session\MarkSessionAttendanceRequest;
use App\Http\Requests\Session\RescheduleSessionRequest;
use App\Http\Resources\Session\ClassSessionResource;
use App\Models\ClassSession;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PayoutService;
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
        private readonly PayoutService $payoutService,
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
            ->when($request->user()->isAdmin(), fn ($q) => $this->onlyPaidSessions($q))
            ->when($request->filled('status'), fn ($q) => $this->applyStatusFilter($q, $request->string('status')->value(), $request->user()->isAdmin()))
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
            // has_makeup: زر "إنشاء جلسة تعويضية" لدى الأدمن يختفي بعد إنشائها (تعويضية واحدة لكل جلسة)
            ->when($request->user()->isAdmin(), fn ($q) => $q->with([self::JOIN_LOGS_RELATION, ...self::STUDENT_TIMEZONE_RELATIONS])->withExists('makeupSessions as has_makeup'))
            // المعلم: هل دخل هو والطالب — يحدد ظهور زر "نسخ رابط التقييم" بالجدول. علَمان فقط (لا
            // سجل الانضمام نفسه، فهو لصفحة التفاصيل)، محسوبان ضمن نفس الاستعلام لا استعلام لكل جلسة
            ->when($request->user()->isTeacher(), fn ($q) => $q->withExists([
                'joinLogs as teacher_joined' => fn ($j) => $j->where('role', 'teacher'),
                'joinLogs as student_joined' => fn ($j) => $j->where('role', 'student'),
            ]))
            // الافتراضي desc (الأحدث تاريخاً أولاً) يبقى كما كان لأي طرف آخر يعتمد
            // عليه ضمناً — sort=asc صريح (الأقرب موعداً أولاً) يطلبه استدعاء
            // "جلساتي" في لوحة الطالب تحديداً.
            ->when(
                $request->string('sort', 'desc')->value() === 'asc',
                fn ($q) => $q->orderBy('scheduled_at')->orderBy('id'),
                fn ($q) => $q->orderByDesc('scheduled_at')->orderByDesc('id'),
            )
            ->paginate($request->integer('per_page', 20));

        // مبلغ مستحق المعلم من كل جلسة — للأدمن فقط، لصفحة الجلسات الحالية دفعة واحدة
        if ($request->user()->isAdmin()) {
            $payouts = $this->payoutService->sessionPayoutAmounts($sessions->getCollection());
            $sessions->getCollection()->each(fn (ClassSession $session) => $session->setAttribute(
                'payout_amount',
                $payouts[$session->id] ?? null,
            ));
        }

        return $this->paginate($sessions->through(fn (ClassSession $session) => new ClassSessionResource($session)));
    }

    public function show(ClassSession $session)
    {
        $this->authorize('view', $session);

        $session->load(self::eagerLoad());

        // سجل الانضمام للأدمن ولصاحب الجلسة (يرى من انضمّ من طلابه في صفحة تفاصيلها) — لا للطلاب
        $user = request()->user();
        if ($user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $session->teacher_id) {
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
     *
     * للأدمن فقط: فلاتر مبنية على نتيجة الحضور التلقائي (attendance_outcome، مخفية عن
     * غيره) بدل status وحدها — كل جلسة منتهية status='completed' أياً كان ما حدث فيها،
     * فـ"المكتملة" كانت تخلط المنعقدة فعلاً بغياب المعلم/الطلاب والجلسات غير المدفوعة.
     * المفاتيح أعلاه تبقى كما هي لكل الأطراف؛ مفاتيح الأدمن لغيره تُطابَق حرفياً (لا نتائج).
     */
    private function applyStatusFilter($query, string $status, bool $isAdmin = false)
    {
        if ($isAdmin && ($outcomeFilter = $this->outcomeFilter($status))) {
            return $outcomeFilter($query);
        }

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
     * قائمة الأدمن تعرض الجلسات المدفوعة فقط: طالب واحد على الأقل حجزه/تسجيله مدفوع.
     * جلسة كل حجوزاتها انتهت مهلتها أو أُلغيت أو ما زالت بانتظار الدفع لا تُعقد ولا تُصرف،
     * فتُخفى. بلا أي سطر حضور: جلسة دورة قبل أول تسجيل تبقى ظاهرة (جدول الدورة)، وجلسة
     * باقة قديمة يُحكم فيها بحجزها المالك — نفس احتياط PayoutService.
     */
    private function onlyPaidSessions($query)
    {
        return $query->where(fn ($q) => $q
            ->whereHas('attendees', fn ($a) => ClassSession::constrainToPaidAttendee($a))
            ->orWhere(fn ($noAttendees) => $noAttendees
                ->whereDoesntHave('attendees')
                ->where(fn ($owner) => $owner
                    ->whereNotNull('course_id')
                    ->orWhereHas('booking', fn ($b) => $b->whereIn('status', ClassSession::PAID_BOOKING_STATUSES)))));
    }

    /**
     * held: عُقدت (حضر المعلم وطالب، أو قبل تتبع الحضور/بلا نتيجة — بيانات قديمة).
     * teacher_absent / student_no_show: نتيجتها، ومعها no_show_teacher/no_show_student القديمة (تسجيل يدوي).
     * needs_makeup: قابلة للتعويض ولم تُنشأ تعويضيتها بعد — نفس شروط SessionService::scheduleMakeupByAdmin.
     * not_held: لم تُعقد لسبب غير الغياب — بلا اشتراك مدفوع، أو ملغاة.
     */
    private function outcomeFilter(string $status): ?\Closure
    {
        return match ($status) {
            'held' => fn ($q) => $q->where('status', 'completed')->where(fn ($o) => $o
                ->whereNull('attendance_outcome')
                ->orWhereIn('attendance_outcome', [ClassSession::OUTCOME_ATTENDED, ClassSession::OUTCOME_UNTRACKED])),
            'teacher_absent' => fn ($q) => $q->where(fn ($o) => $o
                ->where(fn ($c) => $c->where('status', 'completed')->where('attendance_outcome', ClassSession::OUTCOME_TEACHER_ABSENT))
                ->orWhere('status', 'no_show_teacher')),
            'student_no_show' => fn ($q) => $q->where(fn ($o) => $o
                ->where(fn ($c) => $c->where('status', 'completed')->where('attendance_outcome', ClassSession::OUTCOME_STUDENT_NO_SHOW))
                ->orWhere('status', 'no_show_student')),
            'needs_makeup' => fn ($q) => $q->where('status', 'completed')
                ->whereIn('attendance_outcome', [ClassSession::OUTCOME_TEACHER_ABSENT, ClassSession::OUTCOME_STUDENT_NO_SHOW])
                ->whereDoesntHave('makeupSessions')
                ->whereHas('attendees', fn ($a) => ClassSession::constrainToPaidAttendee($a)),
            'not_held' => fn ($q) => $q->where(fn ($o) => $o
                ->where(fn ($c) => $c->where('status', 'completed')->where('attendance_outcome', ClassSession::OUTCOME_NO_PAID_ATTENDEE))
                ->orWhere('status', 'cancelled')),
            default => null,
        };
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

    /** ClassSession::studentTimezone لقائمة الأدمن دفعة واحدة — بلا استعلام لكل جلسة */
    private const STUDENT_TIMEZONE_RELATIONS = ['booking.student:id,user_id', 'booking.student.user:id,timezone'];

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
            'course:id,title,subject_id,course_field_id',
            'course.subject:id,name_ar',
            'course.courseField:id,name_ar',
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
        // الوقت يصل نصّاً محلياً خاماً بلا إزاحة، ويُفسَّر بمنطقة الطالب الزمنية
        // (لا متصفح الأدمن)، ثم يُخزَّن بتوقيت التطبيق (UTC).
        $newTime = Carbon::parse($request->validated('scheduled_at'), $session->studentTimezone())->setTimezone(config('app.timezone'));

        $session = $this->rescheduleService->adminReschedule($session, $request->user(), $newTime, $request->validated('notes'));

        $session->load(self::eagerLoad());

        return $this->success(new ClassSessionResource($session), 'تم تعديل موعد الجلسة');
    }

    /**
     * جلسة تعويضية يدوية من الأدمن لجلسة غاب عنها المعلم أو الطلاب. الشروط (نتيجة
     * الحضور، تعويضية واحدة، موعد مستقبلي، لا تعارض) في SessionService::scheduleMakeupByAdmin.
     */
    public function createMakeup(CreateMakeupSessionRequest $request, ClassSession $session)
    {
        // نفس تفسير reschedule أعلاه — بمنطقة الطالب الزمنية ثم يُخزَّن بتوقيت التطبيق
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'), $session->studentTimezone())->setTimezone(config('app.timezone'));

        $makeup = $this->sessionService->scheduleMakeupByAdmin($session, $request->user(), $scheduledAt, $request->validated('notes'));

        $makeup->load(self::eagerLoad());

        return $this->success(new ClassSessionResource($makeup), 'تم إنشاء الجلسة التعويضية', 201);
    }

    /**
     * تسجيل الأدمن حضور المعلم أو أحد الطلاب يدوياً أثناء الجلسة (نافذة روابطها) — يُحتسب كحضور
     * طبيعي في الوقت. الشروط في SessionJoinLogService::recordByAdmin.
     */
    public function markAttendance(MarkSessionAttendanceRequest $request, ClassSession $session)
    {
        $this->joinLogService->recordByAdmin(
            $session,
            $request->user(),
            $request->validated('role'),
            $request->validated('student_id'),
        );

        $session->load([...self::eagerLoad(), self::JOIN_LOGS_RELATION]);

        return $this->success(new ClassSessionResource($session), 'تم تسجيل الحضور');
    }
}
