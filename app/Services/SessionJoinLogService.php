<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\PayoutItem;
use App\Models\SessionJoinLog;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * يسجّل انضمام المعلم صاحب الجلسة أو أحد طلابها عند نجاح طلب رابط الانضمام — لعرض
 * الحضور على الأدمن فقط. لا يغيّر حالة الجلسة ولا session_attendees ولا الرصيد،
 * وأي فشل فيه يُسجَّل في اللوغ ولا يمنع الانضمام نفسه. انضمام الأدمن لا يُسجَّل.
 */
class SessionJoinLogService
{
    use LogsAuditEvents;

    public function __construct(private readonly SessionService $sessions) {}

    /**
     * الأدمن يسجّل حضور المعلم أو أحد طلاب الجلسة يدوياً من نافذة روابطها — من بدء نافذة الانضمام
     * (قبل الموعد بـ EARLY_JOIN_MINUTES) فصاعداً، أثناء الجلسة أو بعد انتهائها. يُكتب الدخول بموعد بدء
     * الجلسة، فيُعدّ حاضراً في الوقت مهما تأخر تسجيل الأدمن. join_count يبقى كما هو (0 لسجل جديد):
     * عدد مرات الدخول الفعلي عبر المنصة، فيُميَّز به الحضور اليدوي في العرض.
     *
     * جلسة لم تُغلق بعد تُحسم نتيجتها عند إغلاقها (SessionService::completeEndedSessions). جلسة مكتملة
     * أُغلقت بنتيجتها فتُعاد حسماً فوراً (recomputeCompletedOutcome) ومنها المستحقات — إلا إن بُني على
     * نتيجتها ما لا يُعاد حسابه: أُدرجت في كشف مستحقات، أو لها جلسة تعويضية أو طلب تعويض/تغيير معلّق.
     */
    public function recordByAdmin(ClassSession $session, User $admin, string $role, ?int $studentId = null): void
    {
        $windowStart = $session->scheduled_at->clone()->subMinutes(SessionService::EARLY_JOIN_MINUTES);

        if (! in_array($session->status, ['scheduled', 'completed'], true) || now()->lt($windowStart)) {
            throw ValidationException::withMessages([
                'session' => ['تسجيل الحضور متاح من بدء وقت الجلسة فقط'],
            ]);
        }

        if ($session->status === 'completed') {
            $this->assertOutcomeCanChange($session);
        }

        $user = $role === 'teacher'
            ? $session->loadMissing('teacher.user')->teacher?->user
            : $session->attendees()->where('student_id', $studentId)->with('student.user')->first()?->student?->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'student_id' => ['هذا الطالب غير مسجّل في الجلسة'],
            ]);
        }

        DB::transaction(function () use ($session, $admin, $role, $studentId, $user) {
            $log = SessionJoinLog::firstOrNew(['class_session_id' => $session->id, 'user_id' => $user->id]);
            $old = $log->exists ? $log->only(['first_joined_at', 'last_joined_at']) : [];

            $log->fill([
                'role' => $role,
                'student_id' => $role === 'student' ? $studentId : null,
                'first_joined_at' => $session->scheduled_at,
                // دخول فعلي سابق ضمن الجلسة يبقى آخر دخول؛ دخول من موعد سابق قبل تغييره لا يُحتسب
                'last_joined_at' => $log->last_joined_at?->gt($session->scheduled_at) ? $log->last_joined_at : $session->scheduled_at,
                'join_count' => $log->join_count ?? 0,
            ])->save();

            $this->audit('session.attendance_marked', $session, $old, [
                'role' => $role,
                'user_id' => $user->id,
                'student_id' => $role === 'student' ? $studentId : null,
            ], null, $admin->id);

            if ($session->status === 'completed') {
                $this->sessions->recomputeCompletedOutcome($session);
            }
        });
    }

    /** ما بُني على نتيجة الجلسة المكتملة ولا يُعاد حسابه تلقائياً — يمنع تغييرها */
    private function assertOutcomeCanChange(ClassSession $session): void
    {
        $blocker = match (true) {
            PayoutItem::where('class_session_id', $session->id)->exists() => 'أُدرجت الجلسة في كشف مستحقات — لا يمكن تعديل حضورها',
            $session->makeupSessions()->exists() => 'للجلسة جلسة تعويضية — لا يمكن تعديل حضورها',
            $session->rescheduleRequests()->where('status', 'pending')->exists() => 'على الجلسة طلب تعويض أو تغيير موعد معلّق — يُبت فيه أولاً',
            default => null,
        };

        if ($blocker) {
            throw ValidationException::withMessages(['session' => [$blocker]]);
        }
    }

    public function record(ClassSession $session, User $user): void
    {
        try {
            $participant = $this->participant($session, $user);

            if (! $participant) {
                return;
            }

            $now = now();
            $log = SessionJoinLog::firstOrNew(['class_session_id' => $session->id, 'user_id' => $user->id]);

            if ($log->exists) {
                $log->update(['last_joined_at' => $now, 'join_count' => $log->join_count + 1]);

                return;
            }

            $log->fill([...$participant, 'first_joined_at' => $now, 'last_joined_at' => $now, 'join_count' => 1])->save();
        } catch (Throwable $e) {
            Log::warning('session_join_log.failed', [
                'class_session_id' => $session->id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{role: string, student_id: int|null}|null */
    private function participant(ClassSession $session, User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        if ($user->loadMissing('teacher')->teacher?->id === $session->teacher_id) {
            return ['role' => 'teacher', 'student_id' => null];
        }

        $studentId = $user->loadMissing('student')->student?->id;

        if ($studentId && $session->attendees()->where('student_id', $studentId)->exists()) {
            return ['role' => 'student', 'student_id' => $studentId];
        }

        return null;
    }
}
