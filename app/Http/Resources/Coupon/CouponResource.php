<?php

namespace App\Http\Resources\Coupon;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'packageId' => $this->package_id,
            'packageTitle' => $this->whenLoaded('package', fn () => $this->package?->title),
            'discountType' => $this->discount_type,
            'discountValue' => (float) $this->discount_value,
            'maxRedemptions' => $this->max_redemptions,
            'redeemedCount' => $this->redeemed_count,
            'expiresAt' => optional($this->expires_at)->toIso8601String(),
            'isActive' => (bool) $this->is_active,
            'isRedeemable' => $this->isRedeemable(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
