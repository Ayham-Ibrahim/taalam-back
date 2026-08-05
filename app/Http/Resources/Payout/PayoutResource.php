<?php

namespace App\Http\Resources\Payout;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'providerName' => $this->teacher?->user?->name,
            'providerType' => $this->teacher?->teacher_type,
            'periodStart' => optional($this->period_start)->toDateString(),
            'periodEnd' => optional($this->period_end)->toDateString(),
            'sessionsCount' => $this->sessions_count,
            'totalAmount' => (float) $this->net_amount,
            'status' => $this->status,
            'approvedAt' => optional($this->approved_at)->toDateString(),
            'paidAt' => optional($this->paid_at)->toDateString(),
            'transferReference' => $this->transfer_reference,
        ];
    }
}
