<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $attributes = [
        'currency' => 'USD',
    ];

    protected $fillable = [
        'teacher_id',
        'period_start',
        'period_end',
        'gross_amount',
        'deductions',
        'net_amount',
        'currency',
        'sessions_count',
        'status',
        'approved_at',
        'approved_by',
        'paid_at',
        'transfer_reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(PayoutItem::class);
    }
}
