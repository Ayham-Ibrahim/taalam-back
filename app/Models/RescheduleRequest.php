<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RescheduleRequest extends Model
{
    protected $fillable = [
        'class_session_id',
        'booking_id',
        'requested_by',
        'requester_role',
        'current_scheduled_at',
        'proposed_scheduled_at',
        'admin_alternative_at',
        'within_free_window',
        'hours_before_session',
        'reason',
        'status',
        'counterparty_response',
        'counterparty_deadline',
        'counterparty_responded_at',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
        'rejection_reason',
        'sla_due_at',
        'escalated',
    ];

    protected function casts(): array
    {
        return [
            'current_scheduled_at' => 'datetime',
            'proposed_scheduled_at' => 'datetime',
            'admin_alternative_at' => 'datetime',
            'within_free_window' => 'boolean',
            'counterparty_deadline' => 'datetime',
            'counterparty_responded_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'escalated' => 'boolean',
        ];
    }

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
