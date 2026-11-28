<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;

class ComplaintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent() || $user->isTeacher() || $user->isAcademicSupport();
    }

    public function view(User $user, Complaint $complaint): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $user->loadMissing(['student', 'teacher']);

        return ($user->student && $user->student->id === $complaint->student_id)
            || ($user->teacher && $user->teacher->id === $complaint->teacher_id)
            || ($complaint->filed_by_user_id !== null && (int) $complaint->filed_by_user_id === $user->id);
    }

    /**
     * الطريق الوحيد للأدمن إلى نص أي محادثة: شكوى مرتبطة بها (إبلاغ من داخلها). شكوى
     * بلا conversation_id لا تفتح أي محادثة، حتى لو كانت بين نفس الطرفين.
     */
    public function viewConversation(User $user, Complaint $complaint): bool
    {
        return $user->isAdmin() && $complaint->conversation_id !== null;
    }

    public function create(User $user): bool
    {
        $user->loadMissing(['student', 'teacher']);

        return $user->student !== null || $user->teacher !== null;
    }

    public function resolve(User $user): bool
    {
        return $user->isAdmin();
    }

    public function escalate(User $user): bool
    {
        return $user->isAdmin();
    }
}
