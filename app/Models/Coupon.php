<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code',
        'package_id',
        'teacher_id',
        'support_package_id',
        'created_by',
        'discount_type',
        'discount_value',
        'max_redemptions',
        'redeemed_count',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function isPercent(): bool
    {
        return $this->discount_type === 'percent';
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    /** باقات كوبون الأدمن متعدد الباقات فقط (package_id و support_package_id كلاهما null له) */
    public function packages()
    {
        return $this->belongsToMany(Package::class)->withTimestamps();
    }

    /** دورات المراكز التدريبية المشمولة بكوبون الأدمن متعدد الباقات */
    public function courses()
    {
        return $this->belongsToMany(Course::class)->withTimestamps();
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function isMultiPackage(): bool
    {
        return $this->package_id === null && $this->support_package_id === null;
    }

    public function scopeMultiPackage($query)
    {
        return $query->whereNull('package_id')->whereNull('support_package_id');
    }

    public function supportPackage()
    {
        return $this->belongsTo(SupportPackage::class);
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
