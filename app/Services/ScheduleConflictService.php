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
 * "قائمة فعلاً" هنا تعني جلسة بحالة scheduled/active يخص حضورَها حجزٌ أو تسجيلٌ
 * ما زال قائماً (pending_payment أو confirmed أو active) — الجلسات تُنشأ فور
 * الموافقة/الانضمام بصرف النظر عن اكتمال الدفع (join_url هو ما ينتظر الدفع، لا
 * وجود السطر نفسه في class_sessions). لا تُحتسَب:
 *   - جلسات cancelled/completed/no_show_* (حالة الجلسة نفسها).
 *   - جلسات حجزٍ انتهت مهلته (expired) أو أُلغي (cancelled) / تسجيلٍ مُلغى أو
 *     منسحَب منه — صف class_sessions يبقى scheduled بعد ذلك لكنه لم يعد التزاماً،
 *     فلا يمنع حجزاً جديداً على نفس الموعد (نفس شرط ClassSession::visibleTo).
 *   - جلسات انقضى وقتها كاملاً (scheduled_at + المدة ≤ الآن) — لم تعد تشغل الوقت.
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
            ->whereIn('status', self::LIVE_STATUSES)
            ->when($excludeSessionIds, fn ($q) => $q->whereNotIn('id', $excludeSessionIds))
            ->where(function ($query) use ($student, $teacherId) {
                // (أ) جلسة يحضرها هذا الطالب باشتراكٍ ما زال قائماً — لا حجز/تسجيل
                //     مُلغى أو منتهية مهلته. بعد إلغاء/انتهاء الحجز يبقى صف الجلسة
                //     في class_sessions بحالة scheduled، لكنه لم يعد التزاماً فعلياً
                //     فلا يجوز أن يمنع حجزاً جديداً على نفس الموعد.
                $query->whereHas('attendees', function ($a) use ($student) {
                    $a->where('student_id', $student->id);
                    ClassSession::constrainToLiveAttendee($a);
                })
                    // (ب) أو جلسة لهذا المعلم لم يبطل كل حضورها (طالب واحد على الأقل
                    //     باشتراك قائم)، أو جلسة دورة لا حضور مؤكَّد عليها بعد.
                    ->orWhere(function ($q) use ($teacherId) {
                        $q->where('teacher_id', $teacherId)
                            ->where(function ($q2) {
                                $q2->whereDoesntHave('attendees')
                                    ->orWhereHas('attendees', fn ($a) => ClassSession::constrainToLiveAttendee($a));
                            });
                    });
            })
            ->get(['id', 'teacher_id', 'scheduled_at', 'duration_min']);

        // جلسة انقضى وقتها كاملاً (scheduled_at + المدة ≤ الآن) لم تعد تشغل أي وقت
        // على الجدول، فلا يجوز أن تُحسَب في التعارض — لا شيء في الباك اند يضبط
        // status='completed' فتبقى 'scheduled' إلى الأبد، لذا الفلترة بالوقت لا
        // بالحالة. الجلسة الجارية الآن (بدأت ولم تنتهِ) تبقى محسوبة — ما زالت تشغل وقتها.
        $now = now();
        $existingSessions = $existingSessions->filter(
            fn (ClassSession $s) => $s->scheduled_at->copy()->addMinutes($s->duration_min)->gt($now)
        );

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
