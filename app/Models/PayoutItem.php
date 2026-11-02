<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutItem extends Model
{
    /** مستحق جلسة مكتملة لأول مرة */
    public const TYPE_SESSION = 'session';

    /** فرق جلسة دورة/باقة جماعية صُرفت سابقاً ثم زادت قيمتها بتسجيل/انضمام متأخر */
    public const TYPE_SETTLEMENT = 'settlement';

    protected $attributes = [
        'type' => self::TYPE_SESSION,
    ];

    protected $fillable = [
        'payout_id',
        'class_session_id',
        'type',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payout()
    {
        return $this->belongsTo(Payout::class);
    }

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }
}
