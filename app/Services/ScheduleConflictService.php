<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * يمنع أي طالب من امتلاك جلستين متداخلتين زمنياً في نفس اللحظة، وبالمثل يمنع
 * أي معلم من امتلاك جلستين متداخلتين (ولو مع طالبين مختلفين تماماً) — بصرف
 * النظر عن مصدرهما (باقة فردية/جماعية أو دورة، حجز ذاتي أو يدوي من الأدمن).
 * الفحص الثاني (المعلم) كان غائباً تماماً سابقاً: التحقق كان مقيَّداً بجلسات
 * الطالب نفسه فقط، فطالبان مختلفان يحجزان نفس المعلم في نفس اللحظة تماماً
 * كان يمر بلا أي منع — يزدحم جدول المعلم بجلستين متطابقتي التوقيت.
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
     * @param  array<int, int>  $excludeSessionIds  جلسات جماعية/دورة قائمة بالفعل والطالب على وشك
     *   الانضمام إليها كحاضر إضافي فيها (لا تُنشأ له جلسة جديدة) — هذه ليست "جلسة أخرى" تتعارض مع
     *   نفسها، فتُستبعَد من فحص تعارض المعلم صراحة، وإلا يُرفض كل انضمام ثانٍ فما فوق لأي مجموعة/دورة
     *   بحجة "تعارض" مع الجلسة ذاتها التي أنشأها أول طالب انضمّ إليها.
     *
     * @throws ValidationException عند تقاطع أي لحظة مقترحة مع جلسة قائمة فعلاً إما للطالب نفسه أو للمعلم نفسه (مع أي طالب آخر)
     */
    public function assertNoConflict(Student $student, int $teacherId, iterable $candidateStarts, int $candidateDurationMinutes, array $excludeSessionIds = []): void
    {
        $existingSessions = ClassSession::query()
            ->where(function ($query) use ($student, $teacherId) {
                $query->whereHas('attendees', fn ($q) => $q->where('student_id', $student->id))
                    ->orWhere('teacher_id', $teacherId);
            })
            ->whereIn('status', self::LIVE_STATUSES)
            ->when($excludeSessionIds, fn ($q) => $q->whereNotIn('id', $excludeSessionIds))
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
