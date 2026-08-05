<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'enrollment_id',
        'student_id',
        'stripe_session_id',
        'stripe_payment_intent',
        'stripe_charge_id',
        'amount',
        'provider_amount',
        'platform_amount',
        'currency',
        'method',
        'status',
        'paid_at',
        'failure_reason',
        'gateway_payload',
        'invoice_number',
        'invoice_path',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'provider_amount' => 'decimal:2',
            'platform_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'gateway_payload' => 'array',
        ];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
