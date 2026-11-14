<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * حملة إعلانية برابط تسجيل خاص (/campaign/{slug}) — الحساب المسجَّل منها يرى باقات
 * الحملة المخفضة (packages.audience = campaign) ويحق له حجز واحدة منها.
 */
class Campaign extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /** التسجيل مفتوح: مفعّلة وضمن فترتها (إن حُددت) */
    public function isOpen(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
