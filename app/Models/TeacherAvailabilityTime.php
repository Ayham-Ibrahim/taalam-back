<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAvailabilityTime extends Model
{
    protected $fillable = [
        'teacher_id',
        'day_of_week',
        'start_time',
        'end_time',
        'sort_order',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
