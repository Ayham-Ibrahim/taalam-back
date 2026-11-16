<?php

namespace App\Http\Resources\Session;

use App\Models\SessionAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * download_url رابط موقّع مؤقت (RULE-06) — لا يُولَّد إلا لمن مرّ من scopeVisibleTo،
 * فلا يصل رابط ملف طالب لزميله في جلسة المجموعة.
 */
class SessionAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'class_session_id' => $this->class_session_id,
            'title' => $this->title,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploader_role' => $this->uploader_role,
            'uploader' => $this->whenLoaded('uploader', fn () => [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ]),
            'can_delete' => (bool) $user?->can('delete', $this->resource),
            'download_url' => URL::temporarySignedRoute(
                'session-attachments.download',
                now()->addMinutes(SessionAttachment::URL_TTL_MINUTES),
                ['attachment' => $this->id],
            ),
            'created_at' => $this->created_at,
        ];
    }
}
