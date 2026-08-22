<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\SessionAttendee;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SessionService
{
    use LogsAuditEvents;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly BigBlueButtonService $bbb,
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

        if ($now->lt($session->scheduled_at)) {
            return ['joinable' => false, 'message' => 'لم تبدأ هذه الجلسة بعد، يرجى الانتظار حتى موعدها ثم إعادة المحاولة.'];
        }

        if ($now->gt($endsAt)) {
            return ['joinable' => false, 'message' => 'انتهت هذه الجلسة ولم تعد متاحة للانضمام.'];
        }

        if ($this->bbb->meetingExists($session->bbb_meeting_id)) {
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

    public function createMakeupSession(ClassSession $original): ClassSession
    {
        return ClassSession::create([
            'booking_id' => $original->booking_id,
            'course_id' => $original->course_id,
            'teacher_id' => $original->teacher_id,
            'sequence_no' => $original->sequence_no,
            'scheduled_at' => $original->scheduled_at->copy()->addWeek(),
            'duration_min' => $original->duration_min,
            'status' => 'scheduled',
            'is_makeup' => true,
            'makeup_for_session_id' => $original->id,
        ]);
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

        $booking->update([
            'sessions_used' => $newUsed,
            'sessions_remaining' => $booking->sessions_total - $newUsed,
        ]);
    }

}
