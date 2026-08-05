<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionAttendee extends Model
{
    protected $fillable = [
        'class_session_id',
        'student_id',
        'booking_id',
        'enrollment_id',
        'attendance',
        'joined_at',
        'left_at',
        'duration_minutes',
        'absence_notified_at',
        'absence_reason',
        'deducted_from_balance',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'absence_notified_at' => 'datetime',
            'deducted_from_balance' => 'boolean',
        ];
    }

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }
}
