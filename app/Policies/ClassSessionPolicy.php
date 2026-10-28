<?php

namespace App\Policies;

use App\Models\ClassSession;
use App\Models\User;

class ClassSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher() || $user->isStudent();
    }

    /**
     * رؤية تفاصيل الجلسة: لا يكفي وجود سطر حضور للطالب؛ يجب أن يمثّل اشتراكاً
     * قائماً (لا expired/cancelled) — نفس شرط ClassSession::scopeVisibleTo. حجز
     * pending_payment يمرّ هنا (يرى جلسته قبل الدفع) لكنه لا يمرّ من join أدناه.
     */
    public function view(User $user, ClassSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->loadMissing('teacher')->teacher?->id === $session->teacher_id) {
            return true;
        }

        $studentId = $user->loadMissing('student')->student?->id;

        if ($studentId === null) {
            return false;
        }

        $query = $session->attendees()->where('student_id', $studentId);
        ClassSession::constrainToLiveAttendee($query);

        return $query->exists();
    }

    /**
     * بوابة الانضمام (زر "انضم" ورابط بريد التذكير): الطالب يحتاج اشتراكاً مدفوعاً
     * فعلاً، لا مجرد قائم — وإلا دخل صاحب حجز pending_payment جلسة مجموعة غرفتها
     * أُنشئت لأن طالباً آخر دفع. pending_payment بلا دفع لا يمرّ حتى لو لم يلحق
     * المجدول بتحويله expired بعد.
     */
    public function join(User $user, ClassSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->loadMissing('teacher')->teacher?->id === $session->teacher_id) {
            return true;
        }

        $studentId = $user->loadMissing('student')->student?->id;

        return $studentId !== null && $session->studentHasPaidAccess($studentId);
    }

    /** تعديل موعد الجلسة مباشرة (بلا طلب تغيير موعد) — للأدمن وحده. */
    public function reschedule(User $user, ClassSession $session): bool
    {
        return $user->isAdmin();
    }
}
