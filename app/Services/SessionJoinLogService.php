<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\SessionJoinLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * يسجّل انضمام المعلم صاحب الجلسة أو أحد طلابها عند نجاح طلب رابط الانضمام — لعرض
 * الحضور على الأدمن فقط. لا يغيّر حالة الجلسة ولا session_attendees ولا الرصيد،
 * وأي فشل فيه يُسجَّل في اللوغ ولا يمنع الانضمام نفسه. انضمام الأدمن لا يُسجَّل.
 */
class SessionJoinLogService
{
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
