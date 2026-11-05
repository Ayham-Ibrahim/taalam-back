<?php

namespace App\Services;

use App\Jobs\CreateBbbRoomJob;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\SessionAttendee;
use App\Models\SessionJoinLog;
use App\Models\User;
use App\Notifications\MakeupSessionScheduled;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SessionService
{
    use LogsAuditEvents;

    /** يُسمح بالانضمام قبل الموعد المجدول بهذه المدة — ريثما يستعد الطرفان قبل بدء الجلسة فعلياً. */
    public const EARLY_JOIN_MINUTES = 5;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly BigBlueButtonService $bbb,
        private readonly NotificationService $notifications,
        private readonly ScheduleConflictService $conflicts,
    ) {}

    /**
     * ينشئ غرفة BBB فعلية عبر الـ API الحقيقي (create + روابط انضمام موقّعة).
     * إن لم يكن الخادم مهيَّأً بعد (BBB_URL/BBB_SECRET) أو تعذّر الاتصال،
     * يُحفظ رابط انضمام صالح شكلياً على أي حال حتى لا تتعطل دورة حياة الحجز/الجلسة —
     * سيعمل فعلياً بمجرد ضبط بيانات الاعتماد.
     */
    public function createBbbRoom(ClassSession $session): ClassSession
    {
        if ($session->bbb_meeting_id) {
            return $session;
        }

        $meetingId = (string) Str::uuid();
        $attendeePw = Str::random(16);
        $moderatorPw = Str::random(16);

        $created = $this->bbb->createMeeting($meetingId, "TAALAM Session #{$session->id}", $attendeePw, $moderatorPw);

        if (! $created) {
            Log::info("bbb.room_not_created_yet", ['session_id' => $session->id]);
        }

        $session->update([
            'bbb_meeting_id' => $meetingId,
            'bbb_attendee_pw' => $attendeePw,
            'bbb_moderator_pw' => $moderatorPw,
            'join_url_student' => $this->bbb->buildJoinUrl($meetingId, $attendeePw, 'Student'),
            'join_url_teacher' => $this->bbb->buildJoinUrl($meetingId, $moderatorPw, 'Teacher'),
        ]);

        return $session;
    }

    /**
     * فتح رابط BBB الخام مباشرةً كان يُظهر XML غير منسّق مباشرة في المتصفح
     * كلما لم يكن الاجتماع موجوداً فعلياً على BBB (السر غير مضبوط، لم تُنشأ
     * الغرفة بعد، أو انتهت الجلسة) — لأن فتح رابط لا يمكن "التقاط" فشله بعد
     * التنقّل. هذه الدالة تتحقق أولاً أن الغرفة موجودة فعلاً على BBB وتُرجع
     * رسالة عربية واضحة بدل ذلك حين لا تكون كذلك، بدل إعادة رابط BBB الخام.
     *
     * ترتيب الفحوص مهم: نافذة وقت الجلسة (بدأت؟ انتهت؟) تُفحص أولاً وتحسم
     * الأمر فوراً برسالة واضحة، وفقط ضمن النافذة الصحيحة يُفحص وجود الغرفة
     * فعلياً على BBB (meetingExists لا isMeetingRunning — الثانية تُرجع false
     * لأي اجتماع لم ينضم إليه أحد بعد حتى لو أُنشئ بنجاح تام، فتمنع أول
     * انضمام لأي جلسة إلى الأبد؛ راجع توثيق BigBlueButtonService::meetingExists).
     *
     * @return array{joinable: bool, url?: string, message?: string}
     */
    public function resolveJoinUrl(ClassSession $session, User $user): array
    {
        $isTeacher = $user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $session->teacher_id;
        $joinUrl = $isTeacher ? $session->join_url_teacher : $session->join_url_student;

        if (! $joinUrl || ! $session->bbb_meeting_id) {
            return ['joinable' => false, 'message' => 'لم يتم إعداد رابط الجلسة بعد، يرجى المحاولة لاحقاً أو التواصل مع الدعم.'];
        }

        $now = now();
        $endsAt = $session->scheduled_at->clone()->addMinutes($session->duration_min);
        $joinableFrom = $session->scheduled_at->clone()->subMinutes(self::EARLY_JOIN_MINUTES);

        if ($now->lt($joinableFrom)) {
            return ['joinable' => false, 'message' => 'لم تبدأ هذه الجلسة بعد، يرجى الانتظار حتى موعدها ثم إعادة المحاولة.'];
        }

        if ($now->gt($endsAt)) {
            return ['joinable' => false, 'message' => 'انتهت هذه الجلسة ولم تعد متاحة للانضمام.'];
        }

        if ($this->bbb->meetingExists($session->bbb_meeting_id)) {
            return ['joinable' => true, 'url' => $joinUrl];
        }

        // الغرفة قد لا تكون أُنشئت فعلاً على BBB من الأساس (مثال: بيانات اعتماد
        // BBB لم تكن مضبوطة بعد وقت جدولة الجلسة عبر CreateBbbRoomJob، أو فشل
        // استدعاء create وقتها لأي سبب عابر) — createBbbRoom لا يُعيد المحاولة
        // أبداً لجلسة معها bbb_meeting_id محفوظ سلفاً (يتوقف مبكراً)، فتبقى
        // الجلسة محظورة الانضمام إلى الأبد حتى لو أُصلح إعداد BBB لاحقاً. محاولة
        // إنشاء نفس الغرفة (نفس meetingId وكلمتَي المرور المحفوظتين — create آمن
        // التكرار على BBB لنفس meetingID) هنا مرة أخيرة قبل الاستسلام تجعل
        // الجلسة "تُشفى ذاتياً" بمجرد أن يصبح BBB متاحاً فعلاً، بلا أي تدخل يدوي.
        // كلمتا المرور قد تكونان null لجلسة قديمة جداً أُنشئت قبل حفظهما — لا
        // يصح استدعاء createMeeting بمعاملات ناقصة (تعطي TypeError صريحاً بدل
        // false هادئة)، فنتخطى إعادة المحاولة حينها ونسقط مباشرة لرسالة الفشل.
        if ($session->bbb_attendee_pw && $session->bbb_moderator_pw && $this->bbb->createMeeting(
            $session->bbb_meeting_id,
            "TAALAM Session #{$session->id}",
            $session->bbb_attendee_pw,
            $session->bbb_moderator_pw,
        )) {
            return ['joinable' => true, 'url' => $joinUrl];
        }

        return ['joinable' => false, 'message' => 'تعذّر الوصول إلى غرفة الجلسة حالياً، يرجى المحاولة خلال لحظات أو التواصل مع الدعم.'];
    }

    public function markPresent(SessionAttendee $attendee): SessionAttendee
    {
        $attendee->update([
            'attendance' => 'present',
            'joined_at' => $attendee->joined_at ?? now(),
        ]);

        $this->consumeSessionCredit($attendee);

        return $attendee->fresh();
    }

    /**
     * غياب الطالب. إشعار مسبق ≥ advance_notice_hours (6 افتراضياً) → excused ولا تُحتسب
     * من رصيده. أقل من ذلك (أو بلا إشعار) → absent وتُحتسب من رصيده.
     */
    public function recordStudentAbsence(SessionAttendee $attendee, ?Carbon $notifiedAt = null, ?string $reason = null): SessionAttendee
    {
        $attendee->loadMissing('session');
        $session = $attendee->session;

        $advanceHours = (int) $this->settings->get('advance_notice_hours', 6);
        $hasEnoughNotice = $notifiedAt !== null
            && $notifiedAt->diffInHours($session->scheduled_at) >= $advanceHours;

        if ($hasEnoughNotice) {
            $attendee->update([
                'attendance' => 'excused',
                'absence_notified_at' => $notifiedAt,
                'absence_reason' => $reason,
                'deducted_from_balance' => false,
            ]);

            return $attendee->fresh();
        }

        $attendee->update([
            'attendance' => 'absent',
            'absence_reason' => $reason,
            'deducted_from_balance' => true,
        ]);

        $this->consumeSessionCredit($attendee);

        return $attendee->fresh();
    }

    /**
     * غياب المعلم: الجلسة no_show_teacher، الطلاب لا يخسرون من رصيدهم، تُنشأ جلسة
     * تعويضية مجانية تلقائياً (بحاجة لإعادة جدولة لاحقاً عبر RescheduleService)،
     * ويزداد عداد غياب المعلم — مع تحذير/تعليق تلقائي عند بلوغ العتبة.
     */
    public function recordTeacherNoShow(ClassSession $session, ?string $notes = null): ClassSession
    {
        $session->update(['status' => 'no_show_teacher']);
        $session->loadMissing(['teacher', 'attendees']);

        foreach ($session->attendees as $attendee) {
            $attendee->update([
                'attendance' => 'excused',
                'deducted_from_balance' => false,
            ]);
        }

        $this->createMakeupSession($session);

        $teacher = $session->teacher;
        $teacher->update(['no_show_count' => $teacher->no_show_count + 1]);

        $warnThreshold = (int) $this->settings->get('teacher_no_show_warning_threshold', 1);
        $suspendThreshold = (int) $this->settings->get('teacher_no_show_suspend_threshold', 3);

        if ($teacher->no_show_count >= $suspendThreshold && $teacher->status !== 'suspended') {
            $teacher->update(['status' => 'suspended']);
            $this->audit(
                'teacher.suspended',
                $teacher,
                [],
                ['status' => 'suspended', 'no_show_count' => $teacher->no_show_count],
                $notes ?? 'تعليق تلقائي بعد تجاوز الحد الأقصى لمرات غياب المعلم',
            );
        } elseif ($teacher->no_show_count >= $warnThreshold) {
            $this->audit(
                'teacher.no_show_warning',
                $teacher,
                [],
                ['no_show_count' => $teacher->no_show_count],
            );
        }

        return $session->fresh(['attendees']);
    }

    /**
     * جلسة تعويضية يدوية من الأدمن — فقط لجلسة انتهت وحسم الحضور التلقائي أن المعلم غاب
     * عنها (teacher_absent) أو حضر ولم يدخلها أي طالب (student_no_show). جلسة عُقدت فعلاً
     * لا تُعوَّض. تعويضية واحدة لكل جلسة؛ إن غاب عنها أحد أيضاً تُعوَّض هي بدورها بنفس
     * الشروط. الموعد يحدده الأدمن: مستقبلي، بلا تعارض للمعلم أو أي طالب مدفوع.
     */
    public function scheduleMakeupByAdmin(ClassSession $original, User $admin, Carbon $scheduledAt, ?string $notes = null): ClassSession
    {
        $compensable = [ClassSession::OUTCOME_TEACHER_ABSENT, ClassSession::OUTCOME_STUDENT_NO_SHOW];

        if ($original->status !== 'completed' || ! in_array($original->attendance_outcome, $compensable, true)) {
            throw ValidationException::withMessages([
                'session' => ['يمكن إنشاء جلسة تعويضية فقط لجلسة منتهية غاب عنها المعلم أو لم يحضرها أي طالب'],
            ]);
        }

        if ($original->makeupSessions()->exists()) {
            throw ValidationException::withMessages([
                'session' => ['لهذه الجلسة جلسة تعويضية بالفعل'],
            ]);
        }

        if ($scheduledAt->lte(now())) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['يجب أن يكون موعد الجلسة التعويضية في المستقبل'],
            ]);
        }

        $paidAttendees = $original->attendees()->with('student.user');
        ClassSession::constrainToPaidAttendee($paidAttendees);
        $students = $paidAttendees->get()->pluck('student')->filter()->unique('id');

        if ($students->isEmpty()) {
            throw ValidationException::withMessages([
                'session' => ['لا يوجد في هذه الجلسة طالب باشتراك مدفوع لتعويضه'],
            ]);
        }

        foreach ($students as $student) {
            $this->conflicts->assertNoConflict($student, $original->teacher_id, [$scheduledAt], $original->duration_min);
        }

        $makeup = DB::transaction(function () use ($original, $admin, $scheduledAt, $notes) {
            $makeup = $this->createMakeupSession($original, $scheduledAt);

            $this->audit('session.makeup_created', $makeup, [], [
                'makeup_for_session_id' => $original->id,
                'attendance_outcome' => $original->attendance_outcome,
                'scheduled_at' => $scheduledAt->toDateTimeString(),
            ], $notes, $admin->id);

            return $makeup;
        });

        $original->loadMissing('teacher.user');

        collect([$original->teacher?->user])
            ->merge($students->map(fn ($student) => $student->user))
            ->filter()
            ->unique('id')
            ->each(fn (User $party) => $this->notifications->send(
                $party,
                new MakeupSessionScheduled($original->scheduled_at, $scheduledAt, $notes),
                'session.makeup_created',
            ));

        return $makeup->fresh();
    }

    /**
     * الجلسة التعويضية كانت تُنشأ بلا أي سطر حضور وبلا غرفة BBB: لا يراها أي طالب
     * (visibleTo تشترط سطر حضور)، ولا يمكن لأحد الانضمام إليها — ومع ذلك يحوّلها
     * completeEndedSessions إلى completed بعد موعدها فتُحتسب ضمن مستحقات المعلم.
     * تُنسخ الآن أسطر حضور الجلسة الأصلية (بنفس الحجز/التسجيل، بحالة registered)،
     * وتُنشأ غرفتها فوراً إن كانت الأصلية مدفوعة أصلاً (لها غرفة)؛ غير ذلك يلتقطها
     * createMissingRooms بمجرد اكتمال الدفع.
     */
    public function createMakeupSession(ClassSession $original, ?Carbon $scheduledAt = null): ClassSession
    {
        $makeup = ClassSession::create([
            'booking_id' => $original->booking_id,
            'course_id' => $original->course_id,
            'teacher_id' => $original->teacher_id,
            'sequence_no' => $original->sequence_no,
            'scheduled_at' => $scheduledAt ?? $original->scheduled_at->copy()->addWeek(),
            'duration_min' => $original->duration_min,
            'status' => 'scheduled',
            'is_makeup' => true,
            'makeup_for_session_id' => $original->id,
        ]);

        // المدفوع فقط — سطر حضور الحجز المنتهي/الملغى يبقى على الأصلية ولا حق له في تعويض
        $paidAttendees = $original->attendees();
        ClassSession::constrainToPaidAttendee($paidAttendees);

        foreach ($paidAttendees->get() as $attendee) {
            SessionAttendee::create([
                'class_session_id' => $makeup->id,
                'student_id' => $attendee->student_id,
                'booking_id' => $attendee->booking_id,
                'enrollment_id' => $attendee->enrollment_id,
                'attendance' => 'registered',
            ]);
        }

        if ($original->bbb_meeting_id) {
            CreateBbbRoomJob::dispatch($makeup->id)->afterCommit();
        }

        return $makeup;
    }

    /**
     * شبكة أمان دورية (CreateMissingBbbRoomsJob): أي جلسة قادمة مدفوعة فعلاً (طالب
     * واحد على الأقل حجزه/تسجيله مؤكَّد) لكن بلا غرفة BBB — لأي مسار لم يُنشئ غرفتها
     * (حجز يدوي أو مجموعة أو تعويضية قبل إصلاحها، أو أي مسار مستقبلي يُنسى فيه ذلك)
     * — تُنشأ غرفتها هنا، فيظهر رابطها ويصل تذكيرها كأي جلسة أخرى. جلسات pending_payment
     * مستبعدة عمداً: الرابط ينتظر الدفع.
     */
    public function createMissingRooms(int $limit = 100): int
    {
        $sessions = ClassSession::query()
            ->where('status', 'scheduled')
            ->whereNull('bbb_meeting_id')
            ->whereRaw('DATE_ADD(scheduled_at, INTERVAL duration_min MINUTE) > ?', [now()])
            ->whereHas('attendees', fn ($a) => ClassSession::constrainToPaidAttendee($a))
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        foreach ($sessions as $session) {
            $this->createBbbRoom($session);
        }

        return $sessions->count();
    }

    /**
     * تُحتسَب الجلسة من رصيد الحجز فقط للباقات (bookings.sessions_used/remaining) —
     * تسجيلات الدورات (enrollments) لا تملك هذا المفهوم أصلاً في المخطط.
     */
    private function consumeSessionCredit(SessionAttendee $attendee): void
    {
        if (! $attendee->booking_id) {
            return;
        }

        $booking = Booking::find($attendee->booking_id);

        if (! $booking || $booking->sessions_used >= $booking->sessions_total) {
            return;
        }

        $newUsed = $booking->sessions_used + 1;
        $isNowComplete = $newUsed >= $booking->sessions_total;

        $booking->update([
            'sessions_used' => $newUsed,
            'sessions_remaining' => $booking->sessions_total - $newUsed,
            // 'completed' معرَّفة أصلاً في enum الحالة لكنها لم تكن تُضبَط هنا قط
            // — يبقى الحجز confirmed/active للأبد حتى بعد استهلاك كل جلساته
            // فعلياً، فيمنع BookingService::assertNoOpenBookingForPackage الطالب
            // من الاشتراك بنفس الباقة مجدداً رغم انتهائها فعلاً.
            ...($isNowComplete && in_array($booking->status, ['confirmed', 'active'], true)
                ? ['status' => 'completed']
                : []),
        ]);
    }

    /**
     * لا شيء آخر في النظام يُحوّل جلسة إلى 'completed' فعلياً بمجرد انتهاء وقتها
     * — تبقى status='scheduled' إلى الأبد سواء حضر الطالب أو غاب (markPresent/
     * recordStudentAbsence يُحدّثان رصيد الحجز فقط، لا حالة الجلسة نفسها). هذا
     * يكسر ثلاثة أجزاء تعتمد حرفياً على status='completed': توليد المستحقات
     * المالية (PayoutService::generateForPeriod يرفض دائماً "لا توجد جلسات
     * مكتملة" لأن لا جلسة تحمل هذه القيمة فعلياً)، تقييم الجلسة (ReviewService)،
     * ومنع تغيير موعد جلسة منتهية (RescheduleService، الذي أضاف فحصاً زمنياً
     * مستقلاً تحسّباً لهذا بالضبط). تُستدعى دورياً (CompleteEndedSessionsJob).
     *
     * الحالة تبقى 'completed' لكل جلسة منتهية كما كانت (لا أثر ظاهر للطالب/المعلم)، لكن
     * تُحسم معها نتيجة الحضور التلقائي من session_join_logs ونسبة مستحق المعلم منها
     * (راجع attendanceOutcome)، ولغياب الطلاب مبلغ التعويض الثابت بدل النسبة — يُثبَّتان
     * لحظتها فلا يغيّر تعديل الإعداد لاحقاً جلسات سابقة. سجل الانضمام لا يُكتب إلا ضمن نافذة الجلسة (resolveJoinUrl)، فهو
     * نهائي بمجرد انتهاء وقتها.
     *
     * جلسة بلا أي حاضر باشتراك مدفوع (حجز expired/cancelled/غير مدفوع — سطر الحضور يبقى
     * بعد إلغائه) تُغلق بنتيجة no_paid_attendee ونسبة صفر، قبل أي فحص دخول، حتى لا يُصرف
     * للمعلم مستحق عن اشتراك لم يُدفع ثمنه (ولا كاملاً عبر untracked). جلسة بلا أي سطر
     * حضور (بيانات قديمة) يُرجَع فيها لحجزها المالك — نفس احتياط PayoutService.
     */
    public function completeEndedSessions(): int
    {
        $trackedSince = SessionJoinLog::trackingStartedAt();
        $noShowCompensation = (float) $this->settings->get('student_no_show_teacher_compensation_amount', 0);
        $lateMinutes = (int) $this->settings->get('student_no_show_after_minutes', 20);
        $completed = 0;

        ClassSession::query()
            ->where('status', 'scheduled')
            ->whereRaw('DATE_ADD(scheduled_at, INTERVAL duration_min MINUTE) < ?', [now()])
            ->with('joinLogs')
            ->withExists([
                'attendees as has_paid_attendee' => fn ($q) => ClassSession::constrainToPaidAttendee($q),
                'attendees as has_any_attendee',
                'booking as has_paid_booking' => fn ($q) => $q->whereIn('status', ClassSession::PAID_BOOKING_STATUSES),
                'booking as is_free_booking' => fn ($q) => $q->whereHas('package', fn ($p) => $p->where('student_price', '<=', 0)),
            ])
            ->chunkById(200, function ($sessions) use ($trackedSince, $noShowCompensation, $lateMinutes, &$completed) {
                foreach ($sessions as $session) {
                    $isPaid = $session->has_any_attendee ? $session->has_paid_attendee : $session->has_paid_booking;

                    $outcome = $isPaid
                        ? $this->attendanceOutcome($session, $trackedSince, $lateMinutes)
                        : ClassSession::OUTCOME_NO_PAID_ATTENDEE;

                    $completed += ClassSession::whereKey($session->id)
                        ->where('status', 'scheduled')
                        ->update([
                            'status' => 'completed',
                            'attendance_outcome' => $outcome,
                            'teacher_payout_percent' => match ($outcome) {
                                ClassSession::OUTCOME_STUDENT_NO_SHOW,
                                ClassSession::OUTCOME_TEACHER_ABSENT,
                                ClassSession::OUTCOME_NO_PAID_ATTENDEE => 0,
                                ClassSession::OUTCOME_UNTRACKED => null,
                                default => 100,
                            },
                            // غياب الطلاب: مبلغ ثابت مرة واحدة للجلسة بدل مستحقها، يتحمّله هامش المنصة.
                            // لا تعويض عن جلسة تعويضية — يُصرف مرة واحدة على الأصلية لكل سلسلة تعويض.
                            // ولا عن جلسة باقة مجانية — لا مستحق للمعلم عنها أصلاً فلا شيء يُعوَّض
                            'teacher_compensation_amount' => $outcome === ClassSession::OUTCOME_STUDENT_NO_SHOW && ! $session->is_makeup && ! $session->is_free_booking
                                ? $noShowCompensation
                                : null,
                        ]);
                }
            });

        return $completed;
    }

    /**
     * الحضور التلقائي فقط: "دخل" = له سجل في session_join_logs آخر انضمام فيه ضمن نافذة
     * الجلسة الحالية (يُستبعد سجل من موعد سابق قبل تغيير الموعد). attendance اليدوي
     * (ومنه excused) لا يدخل هنا — إلغاء الطالب الفعلي يتم بتغيير موعد الجلسة عبر الأدمن.
     *
     * المعلم والطالب على نفس المهلة: يُعدّ حاضراً فقط من دخل خلال أول $lateMinutes دقيقة من بدء
     * الجلسة (student_no_show_after_minutes). المعلم لم يدخل حتى المهلة → غياب المعلم (لا مستحق)،
     * ولو دخل بعدها وحضر الطلاب. دخل المعلم في المهلة ولم يدخل أي طالب حتى المهلة → غياب الطلاب
     * (تعويض ثابت للمعلم)، ولو دخل طالب بعدها.
     */
    private function attendanceOutcome(ClassSession $session, ?Carbon $trackedSince, int $lateMinutes): string
    {
        if ($trackedSince === null || $session->scheduled_at->lt($trackedSince)) {
            return ClassSession::OUTCOME_UNTRACKED;
        }

        $windowStart = $session->scheduled_at->clone()->subMinutes(self::EARLY_JOIN_MINUTES);
        $windowEnd = $session->scheduled_at->clone()->addMinutes($session->duration_min);
        $deadline = $session->scheduled_at->clone()->addMinutes($lateMinutes);

        $joinedOnTime = fn (string $role) => $session->joinLogs->contains(fn (SessionJoinLog $log) => $log->role === $role
            && $log->last_joined_at->between($windowStart, $windowEnd)
            && $this->firstJoinInWindow($log, $windowStart)->lte($deadline));

        if (! $joinedOnTime('teacher')) {
            return ClassSession::OUTCOME_TEACHER_ABSENT;
        }

        return $joinedOnTime('student') ? ClassSession::OUTCOME_ATTENDED : ClassSession::OUTCOME_STUDENT_NO_SHOW;
    }

    /**
     * أول دخول ضمن نافذة الجلسة الحالية. السجل واحد لكل مستخدم في الجلسة (أول وآخر دخول فقط)، فإن
     * كان أول دخول من موعد سابق قبل تغيير الموعد لا يُعرف أول دخول في الموعد الحالي — يُؤخذ آخر
     * دخول (المعروف أنه ضمن النافذة)، وهو تقدير متأخر لا مبكر.
     */
    private function firstJoinInWindow(SessionJoinLog $log, Carbon $windowStart): Carbon
    {
        return $log->first_joined_at->gte($windowStart) ? $log->first_joined_at : $log->last_joined_at;
    }

    /**
     * تُستدعى دورياً (FetchSessionRecordingsJob) — تبحث عن جلسات أُنشئت غرفتها
     * فعلياً على BBB وانتهى موعدها لكن لم يُعثر على رابط تسجيلها بعد، وتستعلم
     * BBB دفعة واحدة (لا استعلام منفصل لكل جلسة) عن أي منها بات تسجيله جاهزاً
     * ومنشوراً. تتوقف عن محاولة جلسة تجاوز عمرها نافذة البحث (افتراضياً 14
     * يوماً عبر recording_lookup_window_days) كي لا تتراكم استعلامات BBB إلى
     * الأبد لجلسات لم يُسجَّل لها شيء أصلاً (فشل صامت، أو كانت قبل تفعيل
     * التسجيل). حذف BBB/ZcaleRight للتسجيلات بعد فترة الاحتفاظ لديهم (6 أشهر
     * حالياً) مسؤوليتهم وحدهم — لا شيء هنا يُخفي/يمسح recording_url بعدها.
     */
    public function fetchAvailableRecordings(): int
    {
        $windowDays = (int) $this->settings->get('recording_lookup_window_days', 14);

        $sessions = ClassSession::query()
            ->whereNotNull('bbb_meeting_id')
            ->whereNull('recording_url')
            ->whereRaw('DATE_ADD(scheduled_at, INTERVAL duration_min MINUTE) <= ?', [now()])
            ->where('scheduled_at', '>=', now()->subDays($windowDays))
            ->get(['id', 'bbb_meeting_id']);

        if ($sessions->isEmpty()) {
            return 0;
        }

        $urlsByMeetingId = $this->bbb->getRecordingUrls($sessions->pluck('bbb_meeting_id')->all());

        if (empty($urlsByMeetingId)) {
            return 0;
        }

        $updated = 0;

        foreach ($sessions as $session) {
            if (isset($urlsByMeetingId[$session->bbb_meeting_id])) {
                $session->update(['recording_url' => $urlsByMeetingId[$session->bbb_meeting_id]]);
                $updated++;
            }
        }

        return $updated;
    }
}
