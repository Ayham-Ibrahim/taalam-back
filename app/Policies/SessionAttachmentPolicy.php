<?php

namespace App\Policies;

use App\Models\ClassSession;
use App\Models\SessionAttachment;
use App\Models\User;

class SessionAttachmentPolicy
{
    /** قائمة مرفقات الجلسة — لكل من يرى الجلسة نفسها (ClassSessionPolicy::view). */
    public function viewAny(User $user, ClassSession $session): bool
    {
        return $user->can('view', $session);
    }

    /**
     * الرفع: الأدمن وصاحب الجلسة، والطالب باشتراك مدفوع فعلاً (نفس بوابة الانضمام
     * ClassSessionPolicy::join) — صاحب حجز pending_payment يرى جلسته لكنه لا يرفع إليها.
     */
    public function create(User $user, ClassSession $session): bool
    {
        return $user->can('join', $session);
    }

    /** الحذف: صاحب الملف نفسه، أو الأدمن. */
    public function delete(User $user, SessionAttachment $attachment): bool
    {
        return $user->isAdmin() || $attachment->uploaded_by === $user->id;
    }
}
