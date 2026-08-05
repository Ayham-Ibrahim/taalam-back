<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => class_basename($this->type),
            'data' => $this->data,
            'readAt' => optional($this->read_at)->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
