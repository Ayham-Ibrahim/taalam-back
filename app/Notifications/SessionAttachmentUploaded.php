<?php

namespace App\Notifications;

use App\Models\SessionAttachment;
use App\Notifications\Concerns\TracksNotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * تُرسَل للطرف الآخر في الجلسة حين يُرفع إليها ملف — راجع SessionAttachmentService::upload.
 * داخل المنصة فقط (database) بلا بريد: رفع عدة ملفات متتالية يعني عدة رسائل بريد مزعجة.
 */
class SessionAttachmentUploaded extends Notification implements ShouldQueue
{
    use Queueable, TracksNotificationLog;

    public function __construct(private readonly SessionAttachment $attachment) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $session = $this->attachment->session;

        return [
            'attachmentId' => $this->attachment->id,
            'sessionId' => $session->id,
            'sessionScheduledAt' => $session->scheduled_at?->toIso8601String(),
            'uploaderName' => $this->attachment->uploader?->name,
            'uploaderRole' => $this->attachment->uploader_role,
            'title' => $this->attachment->title,
            'fileName' => $this->attachment->original_name,
        ];
    }
}
