<?php

namespace App\Services;

use App\Models\ClassSession;
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

    /**
     * الأدمن يسجّل حضور المعلم أو أحد طلاب الجلسة يدوياً من نافذة روابطها — أثناء الجلسة فقط (نفس
     * نافذة الانضمام: قبل الموعد بـ EARLY_JOIN_MINUTES حتى نهايته). يُكتب الدخول بموعد بدء الجلسة،
     * فيُعدّ حاضراً في الوقت مهما تأخر تسجيل الأدمن، ويُحتسب كحضور طبيعي عند انتهاء الجلسة
     * (SessionService::completeEndedSessions) ومنه المستحقات. join_count يبقى كما هو (0 لسجل جديد):
     * عدد مرات الدخول الفعلي عبر المنصة، فيُميَّز به الحضور اليدوي في العرض.
     */
    public function recordByAdmin(ClassSession $session, User $admin, string $role, ?int $studentId = null): void
    {
        $now = now();
        $windowStart = $session->scheduled_at->clone()->subMinutes(SessionService::EARLY_JOIN_MINUTES);
        $windowEnd = $session->scheduled_at->clone()->addMinutes($session->duration_min);

        if ($session->status !== 'scheduled' || $now->lt($windowStart) || $now->gt($windowEnd)) {
            throw ValidationException::withMessages([
                'session' => ['تسجيل الحضور متاح أثناء وقت الجلسة فقط'],
            ]);
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
        });
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
