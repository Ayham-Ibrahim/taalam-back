<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\NotificationLog
 */
class NotificationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'channel' => $this->channel,
            'subject' => $this->subject,
            'status' => $this->status,
            'error' => $this->error,
            'recipientName' => $this->user?->name,
            'recipientEmail' => $this->user?->email,
            'sentAt' => $this->sent_at,
            'createdAt' => $this->created_at,
        ];
    }
}
