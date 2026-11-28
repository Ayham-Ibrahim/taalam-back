<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * الطرفان وحدهما يقرآن ويكتبان. الأدمن لا يقرأ المحادثة من هنا إطلاقاً — اطلاعه حصراً
 * من الشكوى المرتبطة بها (ComplaintPolicy::viewConversation)، ويملك الحظر ورفعه فقط.
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStudent() || $user->isTeacher() || $user->isAcademicSupport();
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return ! $user->isAdmin() && $conversation->hasParticipant($user);
    }

    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function block(User $user): bool
    {
        return $user->isAdmin();
    }
}
