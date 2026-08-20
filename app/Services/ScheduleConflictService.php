<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * يمنع أي طالب من امتلاك جلستين متداخلتين زمنياً في نفس اللحظة، بصرف النظر
 * عن مصدرهما (باقة فردية/جماعية أو دورة، حجز ذاتي أو يدوي من الأدمن) — لا
 * وجود لهذا التحقق سابقاً في أي مسار حجز/تسجيل.
 *
 * "قائمة فعلاً" هنا تعني جلسة بحالة scheduled/active — أي جلسة تخص حجزاً أو
 * تسجيلاً بحالة pending_payment أو confirmed أو active على حد سواء، لأن
 * الجلسات نفسها تُنشأ فور الموافقة/الانضمام بصرف النظر عن اكتمال الدفع من
 * عدمه (join_url هو ما ينتظر الدفع، لا وجود السطر نفسه في class_sessions).
 * جلسات cancelled/completed/no_show_* لا تُحتسَب — لم تعد التزاماً فعلياً.
 */
class ScheduleConflictService
{
    private const LIVE_STATUSES = ['scheduled', 'active'];

    /**
     * @param  iterable<Carbon>  $candidateStarts  لحظات بداية الجلسات المقترحة (بتوقيت UTC/توقيت النظام)
     *
     * @throws ValidationException عند تقاطع أي لحظة مقترحة مع جلسة قائمة فعلاً للطالب نفسه
     */
    public function assertNoConflict(Student $student, iterable $candidateStarts, int $candidateDurationMinutes): void
    {
        $existingSessions = ClassSession::query()
            ->whereHas('attendees', fn ($q) => $q->where('student_id', $student->id))
            ->whereIn('status', self::LIVE_STATUSES)
            ->get(['id', 'scheduled_at', 'duration_min']);

        if ($existingSessions->isEmpty()) {
            return;
        }

        foreach ($candidateStarts as $candidateStart) {
            $candidateEnd = $candidateStart->copy()->addMinutes($candidateDurationMinutes);

            $conflict = $existingSessions->first(function (ClassSession $existing) use ($candidateStart, $candidateEnd) {
                $existingStart = $existing->scheduled_at;
                $existingEnd = $existingStart->copy()->addMinutes($existing->duration_min);

                // تقاطع فترتين نصف مفتوحتين [start, end): تتقاطعان إن بدأت كل
                // واحدة قبل انتهاء الأخرى.
                return $existingStart->lt($candidateEnd) && $candidateStart->lt($existingEnd);
            });

            if ($conflict) {
                throw ValidationException::withMessages([
                    'schedule' => ['لا يمكن الحجز في هذا الوقت لوجود جلسة أخرى متعارضة.'],
                ]);
            }
        }
    }
}
