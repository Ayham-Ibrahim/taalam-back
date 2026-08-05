<?php

namespace App\Http\Resources\Verification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BadgeAwardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacherId' => $this->teacher_id,
            'badgeId' => $this->badge_id,
            'awardedAt' => optional($this->granted_at)->toDateString(),
            'badge' => $this->whenLoaded('badge', fn () => new BadgeResource($this->badge)),
        ];
    }
}
