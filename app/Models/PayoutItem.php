<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayoutItem extends Model
{
    protected $fillable = [
        'payout_id',
        'class_session_id',
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
