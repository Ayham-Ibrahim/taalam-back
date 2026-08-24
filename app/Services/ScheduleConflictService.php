<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\SessionAttendee;
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
            ->get(['id', 'teacher_id', 'scheduled_at', 'duration_min']);

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
                    'schedule' => [$this->conflictMessage($conflict, $teacherId, $student->id)],
                ]);
            }
        }
    }

    /**
     * رسالة عامة غامضة ("جلسة أخرى متعارضة") كانت تصل للمعلم وقت الموافقة
     * بلا أي تفصيل يشرح له ماذا بالضبط تعارض ولماذا — لا يعرف إن كانت المشكلة
     * في جدوله هو، أم في جدول الطالب، ولا متى بالضبط. نُحدِّد صراحة الطرف
     * المتعارض (المعلم نفسه مع طالب مختلف، أم الطالب نفسه مع جلسة أخرى)
     * والتوقيت الفعلي — بتوقيت المعلم صاحب الجلسة المتعارضة تحديداً، لا UTC
     * الخام ولا توقيت من يستدعي هذه الدالة.
     */
    private function conflictMessage(ClassSession $conflict, int $teacherId, int $studentId): string
    {
        $conflict->loadMissing('teacher.user:id,timezone');
        $timezone = $conflict->teacher?->user?->timezone ?? config('app.timezone');
        $when = $conflict->scheduled_at->copy()->setTimezone($timezone)->format('Y-m-d H:i');

        $isSameTeacher = (int) $conflict->teacher_id === $teacherId;
        $isSameStudent = SessionAttendee::where('class_session_id', $conflict->id)
            ->where('student_id', $studentId)
            ->exists();

        // صياغة محايدة (بضمير الغائب لا المخاطَب) عمداً — هذه الدالة تُستدعى من
        // مسارات مختلفة تماماً (طلب ذاتي من الطالب، موافقة المعلم، حجز يدوي من
        // الأدمن)، فمن يظهر له الخطأ يختلف في كل مرة؛ رسالة بصيغة "لديك" كانت
        // ستُضلِّل طالباً يرسل طلباً جديداً بحجة أنه "يقبل" حجزاً.
        if ($isSameTeacher && $isSameStudent) {
            return "لا يمكن الحجز — يوجد بالفعل حجز آخر بين هذا المعلم وهذا الطالب في نفس هذا التوقيت ({$when}).";
        }

        if ($isSameTeacher) {
            return "لا يمكن الحجز في هذا الوقت — يوجد لدى المعلم جلسة أخرى مؤكدة في نفس هذا التوقيت ({$when}) مع طالب مختلف.";
        }

        return "لا يمكن الحجز في هذا الوقت لأن لدى الطالب جلسة أخرى مؤكدة في نفس هذا التوقيت ({$when}).";
    }
}
