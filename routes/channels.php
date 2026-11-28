<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * المصادقة عبر /api/broadcasting/auth بتوكن Sanctum (bootstrap/app.php). الأدمن لا
 * يشترك في أي قناة محادثة — اطلاعه من الشكوى عبر HTTP فقط.
 */

// قناة المستخدم الخاصة: تحديث قائمة المحادثات وعداد غير المقروء (ChatUpdated)
Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

// presence: الطرفان فقط — الأعضاء الحاضرون = حالة اتصال الطرف الآخر داخل المحادثة،
// و "يكتب الآن" whisper من الفرونت مباشرة (accept_client_events_from=members)
Broadcast::channel('conversation.{conversation}', function (User $user, Conversation $conversation) {
    if (! $user->can('view', $conversation)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});
