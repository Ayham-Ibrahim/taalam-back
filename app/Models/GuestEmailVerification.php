<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestEmailVerification extends Model
{
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'email',
        'normalized_email',
        'code_hash',
        'expires_at',
        'attempts',
        'used_at',
        'ip_address',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < self::MAX_ATTEMPTS;
    }
}
