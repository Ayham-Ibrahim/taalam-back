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
     * ملاحظة: هذه هي البوابة الفعلية للانضمام (join) أيضاً — لا مجرد "رؤية"
     * الجلسة. لذا لا يكفي وجود سطر حضور للطالب على هذه الجلسة؛ يجب أن يمثّل
     * اشتراكاً قائماً فعلاً (لا expired/cancelled) — وإلا يبقى بإمكان طالب
     * حجزه باطل الانضمام لجلسة لم يعد يملك حقاً فيها. نفس الشرط بالضبط
     * المستخدم في ClassSession::scopeVisibleTo لإخفاء الجلسة من قائمته أصلاً.
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
}
