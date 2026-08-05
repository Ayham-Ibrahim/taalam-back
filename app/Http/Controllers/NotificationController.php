<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;

/**
 * كل الطرق مقيَّدة ضمنياً بالمستخدم الحالي (notifications() على $request->user())
 * — لا حاجة لسياسة تفويض منفصلة إذ لا يمكن لأي مستخدم رؤية إشعارات غيره أصلاً.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate($request->integer('per_page', 20));

        return $this->paginate($notifications->through(fn ($notification) => new NotificationResource($notification)));
    }

    public function unreadCount(Request $request)
    {
        return $this->success(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return $this->success(new NotificationResource($record->fresh()));
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->success(['count' => 0]);
    }
}
