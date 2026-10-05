<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code',
        'package_id',
        'teacher_id',
        'created_by',
        'discount_percent',
        'max_redemptions',
        'redeemed_count',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasRedemptionsLeft(): bool
    {
        return $this->max_redemptions === null || $this->redeemed_count < $this->max_redemptions;
    }

    public function isRedeemable(): bool
    {
        return $this->is_active && ! $this->isExpired() && $this->hasRedemptionsLeft();
    }
}
