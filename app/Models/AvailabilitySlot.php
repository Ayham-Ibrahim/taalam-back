<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvailabilitySlot extends Model
{
    protected $fillable = [
        'teacher_id',
        'day_of_week',
    ];

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
