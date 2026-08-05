<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChangeRequest extends Model
{
    protected $fillable = [
        'changeable_type',
        'changeable_id',
        'requested_by',
        'type',
        'payload',
        'reason',
        'freeze_start',
        'freeze_end',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
        'objection_deadline',
        'objections_count',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'freeze_start' => 'date',
            'freeze_end' => 'date',
            'reviewed_at' => 'datetime',
            'objection_deadline' => 'datetime',
        ];
    }

    public function changeable()
    {
        return $this->morphTo();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function objections()
    {
        return $this->hasMany(ChangeObjection::class);
    }
}
