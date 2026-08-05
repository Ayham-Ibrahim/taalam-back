<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BadgeAward extends Model
{
    protected $fillable = [
        'badge_id',
        'teacher_id',
        'granted_by',
        'granted_at',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function badge()
    {
        return $this->belongsTo(Badge::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function granter()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
