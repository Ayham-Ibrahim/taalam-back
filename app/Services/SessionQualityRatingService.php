<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\SessionQualityRating;
use App\Models\Student;
use App\Notifications\SessionQualityRatingLinkSent;
use App\Notifications\SessionQualityRatingRequested;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * تقييم جودة الجلسة: طلب تلقائي عند بدء الجلسة ودخول المعلم والطالب إليها (إشعار للطالب برابط
 * التقييم + إشعار شكر للمعلم بنفس الرابط)، وحفظ تقييم الطالب، وعرضه للأدمن فقط.
 */
class SessionQualityRatingService
{
    /** حالات لا تُطلب فيها الجودة ولا يُعرض رابطها */
    private const CLOSED_STATUSES = ['cancelled', 'suspended'];

    /**
     * لا تُرسَل طلبات لجلسات بدأت قبل هذه المدة — حتى لا يصل سيل إشعارات عن كل الجلسات
     * السابقة عند أول تشغيل بعد النشر. الطلب العادي يُرسَل خلال دقيقة من دخول الطرفين.
     */
    private const MAX_REQUEST_DELAY_HOURS = 12;

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * يُشغَّل كل دقيقة (SendSessionQualityRatingRequestsJob). جلسة بدأت ودخلها المعلم وطالب
     * دافع واحد على الأقل (سجل الانضمام SessionJoinLog): كل طالب يصله الطلب لحظة دخوله مرة
     * واحدة (session_attendees.quality_rating_requested_at)، والمعلم يصله إشعار الشكر مرة واحدة
     * مع أول طالب (class_sessions.quality_rating_requested_at). يُرجع عدد الطلاب الذين أُرسل لهم.
     */
    public function sendPendingRequests(): int
    {
        $sent = 0;

        ClassSession::query()
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->where('scheduled_at', '<=', now())
            ->where('scheduled_at', '>=', now()->subHours(self::MAX_REQUEST_DELAY_HOURS))
            ->whereHas('joinLogs', fn ($q) => $q->where('role', 'teacher'))
            ->whereHas('attendees', fn ($a) => $a->whereNull('quality_rating_requested_at'))
            ->with([
                'teacher.user',
                'joinLogs' => fn ($q) => $q->where('role', 'student'),
                'attendees' => fn ($a) => ClassSession::constrainToPaidAttendee($a),
                'attendees.student.user',
            ])
            ->chunkById(100, function ($sessions) use (&$sent) {
                foreach ($sessions as $session) {
                    $joinedStudentIds = $session->joinLogs->pluck('student_id')->filter()->map(fn ($id) => (int) $id)->all();
                    $newlyJoined = $session->attendees->filter(fn ($attendee) => $attendee->quality_rating_requested_at === null
                        && in_array((int) $attendee->student_id, $joinedStudentIds, true));

                    if ($newlyJoined->isEmpty()) {
                        continue;
                    }

                    // الرمز يُنشأ قبل الإشعارات كي تحمل كلها نفس الرابط
                    $session->ensureQualityRatingToken();

                    foreach ($newlyJoined as $attendee) {
                        // يُعلَّم أولاً (لا بعد الإرسال) — فشل بريد واحد لا يعيد الإرسال كل دقيقة
                        $claimed = $attendee->newQuery()->whereKey($attendee->id)
                            ->whereNull('quality_rating_requested_at')
                            ->update(['quality_rating_requested_at' => now()]);

                        $studentUser = $attendee->student?->user;
                        if ($claimed && $studentUser && $this->sendSafely($studentUser, new SessionQualityRatingRequested($session), $session)) {
                            $sent++;
                        }
                    }

                    $teacherClaimed = ClassSession::whereKey($session->id)
                        ->whereNull('quality_rating_requested_at')
                        ->update(['quality_rating_requested_at' => now()]);

                    $teacherUser = $session->teacher?->user;
                    if ($teacherClaimed && $teacherUser) {
                        $this->sendSafely($teacherUser, new SessionQualityRatingLinkSent($session), $session);
                    }
                }
            });

        return $sent;
    }

    /**
     * رابط التقييم متاح (للإشعار ولزر النسخ عند المعلم): بدأت الجلسة، ليست ملغاة، ودخلها المعلم
     * وطالب واحد على الأقل.
     */
    public static function linkAvailable(ClassSession $session): bool
    {
        if (! $session->scheduled_at || $session->scheduled_at->isFuture() || in_array($session->status, self::CLOSED_STATUSES, true)) {
            return false;
        }

        // قائمة الجلسات تحسب العلَمين مسبقاً (ClassSessionController::index) — وإلا من السجل نفسه
        $attributes = $session->getAttributes();
        if (array_key_exists('teacher_joined', $attributes) && array_key_exists('student_joined', $attributes)) {
            return (bool) $attributes['teacher_joined'] && (bool) $attributes['student_joined'];
        }

        $roles = $session->relationLoaded('joinLogs')
            ? $session->joinLogs->pluck('role')
            : $session->joinLogs()->pluck('role');

        return $roles->contains('teacher') && $roles->contains('student');
    }

    /** بيانات الجلسة المعروضة بصفحة التقييم وبلوحة الأدمن */
    public function sessionContext(ClassSession $session): array
    {
        $session->loadMissing([
            'teacher.user:id,name',
            'booking.package:id,title,session_format,subject_id',
            'booking.package.subject:id,name_ar',
            'course:id,title,subject_id,course_field_id',
            'course.subject:id,name_ar',
            'course.courseField:id,name_ar',
        ]);

        $package = $session->booking?->package;
        $course = $session->course;

        return [
            'classSessionId' => $session->id,
            'packageTitle' => $package?->title ?? $course?->title,
            // individual | group | course
            'packageType' => $package?->session_format ?? ($course ? 'course' : null),
            'sessionNumber' => $session->sequence_no,
            'teacherName' => $session->teacher?->user?->name,
            'subjectName' => $package?->subject?->name_ar ?? $course?->subject?->name_ar ?? $course?->courseField?->name_ar,
            'sessionDate' => $session->scheduled_at?->toIso8601String(),
        ];
    }

    /** صفحة التقييم للطالب: بيانات الجلسة + تقييمه السابق إن وُجد */
    public function forStudent(ClassSession $session, Student $student): array
    {
        $this->assertCanRate($session, $student);

        $existing = SessionQualityRating::where('class_session_id', $session->id)->where('student_id', $student->id)->first();

        return [
            'session' => $this->sessionContext($session),
            'rating' => $existing ? $this->ratingValues($existing) : null,
        ];
    }

    public function submit(ClassSession $session, Student $student, array $data): SessionQualityRating
    {
        $this->assertCanRate($session, $student);

        return SessionQualityRating::updateOrCreate(
            ['class_session_id' => $session->id, 'student_id' => $student->id],
            [
                ...collect(SessionQualityRating::CRITERIA)->mapWithKeys(fn ($c) => [$c => (int) $data[$c]])->all(),
                'comment' => $data['comment'] ?? null,
            ],
        );
    }

    /** متوسط المعايير الخمسة — أساس ترتيب "الأعلى/الأدنى جودة" */
    private const AVERAGE_SQL = '(audio_video_quality + connection_stability + teaching_quality + overall_rating + benefit_level) / 5';

    /**
     * لوحة الأدمن: تقييمات الجودة مع بيانات الجلسة. الفلاتر تراكمية: اسم المعلم، اسم الطالب،
     * المادة، والترتيب (latest | highest | lowest حسب متوسط المعايير).
     */
    public function adminList(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $teacherName = trim((string) ($filters['teacher_name'] ?? ''));
        $studentName = trim((string) ($filters['student_name'] ?? ''));
        $subjectId = $filters['subject_id'] ?? null;
        $sort = $filters['sort'] ?? 'latest';

        $ratings = SessionQualityRating::query()
            ->select('session_quality_ratings.*')
            ->selectRaw(self::AVERAGE_SQL.' as average_score')
            ->when($teacherName !== '', fn ($q) => $q->whereHas('classSession.teacher.user', fn ($u) => $u->where('name', 'like', "%{$teacherName}%")))
            ->when($studentName !== '', fn ($q) => $q->whereHas('student.user', fn ($u) => $u->where('name', 'like', "%{$studentName}%")))
            // مادة الباقة أو مادة الدورة — مجمَّعتان بقوس واحد حتى لا يخرج OR عن ربط الجلسة
            ->when($subjectId, fn ($q) => $q->whereHas('classSession', fn ($s) => $s->where(fn ($w) => $w
                ->whereHas('booking.package', fn ($p) => $p->where('subject_id', $subjectId))
                ->orWhereHas('course', fn ($c) => $c->where('subject_id', $subjectId)))))
            ->with([
                'student.user:id,name',
                'classSession.teacher.user:id,name',
                'classSession.booking.package:id,title,session_format,subject_id',
                'classSession.booking.package.subject:id,name_ar',
                'classSession.course:id,title,subject_id,course_field_id',
                'classSession.course.subject:id,name_ar',
                'classSession.course.courseField:id,name_ar',
            ])
            ->when($filters['teacher_id'] ?? null, fn ($q, $teacherId) => $q->whereHas('classSession', fn ($s) => $s->where('teacher_id', $teacherId)))
            ->when($sort === 'highest', fn ($q) => $q->orderByRaw(self::AVERAGE_SQL.' desc'))
            ->when($sort === 'lowest', fn ($q) => $q->orderByRaw(self::AVERAGE_SQL.' asc'))
            ->orderByDesc('session_quality_ratings.created_at')
            ->orderByDesc('session_quality_ratings.id')
            ->paginate($perPage);

        return $ratings->through(fn (SessionQualityRating $rating) => [
            'id' => $rating->id,
            ...$this->sessionContext($rating->classSession),
            'studentName' => $rating->student?->user?->name,
            'ratedAt' => $rating->created_at?->toIso8601String(),
            'averageScore' => round((float) $rating->average_score, 1),
            ...$this->ratingValues($rating),
        ]);
    }

    /** المواد التي لها تقييمات جودة فعلاً — خيارات فلتر المادة بلوحة الأدمن */
    public function subjectOptions(): array
    {
        $sessionIds = SessionQualityRating::query()->distinct()->pluck('class_session_id');

        $sessions = ClassSession::whereIn('id', $sessionIds)
            ->with(['booking.package.subject:id,name_ar', 'course.subject:id,name_ar'])
            ->get(['id', 'booking_id', 'course_id']);

        return $sessions
            ->map(fn ($s) => $s->booking?->package?->subject ?? $s->course?->subject)
            ->filter()
            ->unique('id')
            ->sortBy('name_ar')
            ->map(fn ($subject) => ['id' => $subject->id, 'name' => $subject->name_ar])
            ->values()
            ->all();
    }

    private function ratingValues(SessionQualityRating $rating): array
    {
        return [
            'audioVideoQuality' => $rating->audio_video_quality,
            'connectionStability' => $rating->connection_stability,
            'teachingQuality' => $rating->teaching_quality,
            'overallRating' => $rating->overall_rating,
            'benefitLevel' => $rating->benefit_level,
            'comment' => $rating->comment,
        ];
    }

    /** طالب دافع مسجَّل بالجلسة، والجلسة انتهى وقتها ولم تُلغَ */
    private function assertCanRate(ClassSession $session, Student $student): void
    {
        $isAttendee = $session->attendees()
            ->where('student_id', $student->id)
            ->where(fn ($q) => ClassSession::constrainToPaidAttendee($q))
            ->exists();

        if (! $isAttendee) {
            abort(403, 'لا يمكنك تقييم جلسة لست مسجلاً فيها');
        }

        // متاح من بدء الجلسة (نفس لحظة وصول الرابط)، لا بعد انتهائها فقط
        if ($session->scheduled_at->isFuture() || in_array($session->status, self::CLOSED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'class_session' => ['يمكن تقييم الجلسة بعد بدئها فقط'],
            ]);
        }
    }

    private function sendSafely(mixed $notifiable, $notification, ClassSession $session): bool
    {
        try {
            $this->notifications->send($notifiable, $notification, 'session.quality_rating_requested');

            return true;
        } catch (Throwable $e) {
            Log::error('فشل إرسال طلب تقييم جودة الجلسة', [
                'class_session_id' => $session->id,
                'notifiable_id' => $notifiable->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
