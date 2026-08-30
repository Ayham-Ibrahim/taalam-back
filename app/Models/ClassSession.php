<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        'started_at',
        'ended_at',
        'cancellation_reason',
        'is_makeup',
        'makeup_for_session_id',
        'teacher_notes',
    ];

    protected function casts(): array
    {
        return [
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
     * مرتبط أصلاً يُتجاوز). مشترك بين فرعَي الطالب والمعلم في scopeVisibleTo.
     */
    protected static function constrainToLiveAttendee(Builder $attendees): void
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
}
