<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    protected $fillable = [
        'booking_id',
        'course_id',
        'teacher_id',
        'sequence_no',
        'scheduled_at',
        'duration_min',
        'original_scheduled_at',
        'bbb_meeting_id',
        'bbb_attendee_pw',
        'bbb_moderator_pw',
        'join_url_student',
        'join_url_teacher',
        'reminder_sent_at',
        'recording_url',
        'status',
        'attendance_outcome',
        'teacher_payout_percent',
        'started_at',
        'ended_at',
        'cancellation_reason',
        'is_makeup',
        'makeup_for_session_id',
        'teacher_notes',
    ];

    /**
     * نتيجة الحضور التلقائي ونسبة مستحق المعلم شأن داخلي للأدمن والمستحقات — لا تظهر
     * للطالب/المعلم في أي استجابة تُسلسِل الجلسة كاملة (علاقات sessions داخل الحجز مثلاً).
     * ClassSessionResource يضيفها صراحة للأدمن فقط.
     */
    protected $hidden = [
        'attendance_outcome',
        'teacher_payout_percent',
    ];

    /** دخل المعلم وطالب واحد على الأقل — مستحق كامل */
    public const OUTCOME_ATTENDED = 'attended';

    /** دخل المعلم ولم يدخل أي طالب — نسبة التعويض من الإعداد */
    public const OUTCOME_STUDENT_NO_SHOW = 'student_no_show';

    /** لم يدخل المعلم — لا مستحق */
    public const OUTCOME_TEACHER_ABSENT = 'teacher_absent';

    /** بدأت قبل بدء تسجيل الانضمام (SessionJoinLog::trackingStartedAt) — لا دليل حضور، تُحتسب كاملة */
    public const OUTCOME_UNTRACKED = 'untracked';

    protected function casts(): array
    {
        return [
            'teacher_payout_percent' => 'decimal:2',
            'scheduled_at' => 'datetime',
            'original_scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'is_makeup' => 'boolean',
        ];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function attendees()
    {
        return $this->hasMany(SessionAttendee::class);
    }

    /** سجل الانضمام (زر "انضم") — للعرض على الأدمن فقط، لا يدخل في أي منطق آخر */
    public function joinLogs()
    {
        return $this->hasMany(SessionJoinLog::class);
    }

    public function makeupForSession()
    {
        return $this->belongsTo(ClassSession::class, 'makeup_for_session_id');
    }

    public function rescheduleRequests()
    {
        return $this->hasMany(RescheduleRequest::class, 'class_session_id');
    }

    /**
     * لا عمود student_id مباشر على الجدول (جلسة المجموعة لها طلاب متعددون) —
     * رؤية الطالب تُحدَّد عبر session_attendees. الأدمن يرى الكل، المعلم بـ teacher_id.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isTeacher()) {
            $teacherId = $user->loadMissing('teacher')->teacher?->id ?? 0;

            // جلسة انتهت صلاحية كل حجوزات/تسجيلات طلابها دون إكمال الدفع لن تُعقد
            // فعلياً — تُخفى من جدول المعلم كما تُخفى من جدول الطالب. تبقى ظاهرة ما
            // دام طالب واحد على الأقل له اشتراك قائم (يغطّي جلسة المجموعة التي دفع
            // بعض طلابها وبطل حجز آخرين)، وكذلك الجلسة التي لا حضور عليها بعد
            // (جلسة دورة قبل أي تسجيل مؤكَّد).
            return $query->where('teacher_id', $teacherId)
                ->where(fn ($q) => $q
                    ->whereDoesntHave('attendees')
                    ->orWhereHas('attendees', fn ($a) => self::constrainToLiveAttendee($a))
                );
        }

        if ($user->isStudent()) {
            $studentId = $user->loadMissing('student')->student?->id ?? 0;

            // جلسة الطالب تُحجب من قائمته فور بطلان حجزه/تسجيله المرتبط بها: انتهت
            // مهلة الدفع المؤقتة دون إكمال الدفع (expired/cancelled) أو أُلغي لاحقاً.
            // سطر الحضور يبقى في القاعدة لكنه لم يعد يمثّل اشتراكاً صالحاً، فلا مكان
            // للجلسة ضمن جلسات الطالب النشطة — الحجز/التسجيل نفسه يظل ظاهراً في
            // سجل الحجوزات. الفحص على حجز/تسجيل هذا الطالب تحديداً عبر سطر حضوره،
            // فلا يتأثر جدول جلسة المجموعة/الدورة المشتركة لبقية الطلاب الذين ما
            // زالت اشتراكاتهم صالحة.
            return $query->whereHas('attendees', function ($a) use ($studentId) {
                $a->where('student_id', $studentId);
                self::constrainToLiveAttendee($a);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * يقيّد استعلام session_attendees بالسطور التي ما زالت تمثّل اشتراكاً قائماً:
     * الحجز ليس expired/cancelled، والتسجيل ليس cancelled/withdrawn (وأي طرف غير
     * مرتبط أصلاً يُتجاوز). مشترك بين فرعَي الطالب والمعلم في scopeVisibleTo،
     * وبين ClassSessionPolicy::view أيضاً (نفس شرط "اشتراك قائم" بالضبط يحسم من
     * يملك حق مشاهدة/الانضمام لجلسة، لا فقط من يظهر في قائمة الجلسات).
     */
    public static function constrainToLiveAttendee(Builder|HasMany $attendees): void
    {
        $attendees
            ->where(fn ($q) => $q
                ->whereNull('booking_id')
                ->orWhereHas('booking', fn ($b) => $b->whereNotIn('status', ['expired', 'cancelled']))
            )
            ->where(fn ($q) => $q
                ->whereNull('enrollment_id')
                ->orWhereHas('enrollment', fn ($e) => $e->whereNotIn('status', ['cancelled', 'withdrawn']))
            );
    }

    public const PAID_BOOKING_STATUSES = ['confirmed', 'active', 'completed'];

    public const PAID_ENROLLMENT_STATUSES = ['confirmed', 'in_progress', 'completed'];

    /**
     * أضيق من constrainToLiveAttendee: الاشتراك القائم يكفي لظهور الجلسة في القوائم،
     * أما الانضمام ورابط BBB والتذكير فتشترط اشتراكاً مدفوعاً فعلاً. حجز pending_payment
     * "قائم" لكنه غير مدفوع — وغرفة جلسة المجموعة تُنشأ فور دفع أي طالب آخر، فلو كفى
     * "القائم" لدخل غير الدافع مجاناً (أو احتفظ بالرابط الخام بعد انتهاء مهلته).
     */
    public static function constrainToPaidAttendee(Builder|HasMany $attendees): void
    {
        $attendees->where(fn ($q) => $q
            ->whereHas('booking', fn ($b) => $b->whereIn('status', self::PAID_BOOKING_STATUSES))
            ->orWhereHas('enrollment', fn ($e) => $e->whereIn('status', self::PAID_ENROLLMENT_STATUSES))
        );
    }

    /**
     * يضيف student_has_paid_access لكل جلسة في استعلام قائمة طالب (exists واحد ضمن
     * نفس الاستعلام) — كي لا يسأل ClassSessionResource عن كل جلسة على حدة.
     */
    public function scopeWithStudentPaidAccess(Builder $query, User $user): Builder
    {
        $studentId = $user->isStudent() ? $user->loadMissing('student')->student?->id : null;

        if ($studentId === null) {
            return $query;
        }

        return $query->withExists(['attendees as student_has_paid_access' => function ($a) use ($studentId) {
            $a->where('student_id', $studentId);
            self::constrainToPaidAttendee($a);
        }]);
    }

    public function studentHasPaidAccess(int $studentId): bool
    {
        if (array_key_exists('student_has_paid_access', $this->attributes)) {
            return (bool) $this->attributes['student_has_paid_access'];
        }

        $query = $this->attendees()->where('student_id', $studentId);
        self::constrainToPaidAttendee($query);

        return $query->exists();
    }
}
