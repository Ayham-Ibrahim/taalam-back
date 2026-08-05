<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'student_id',
        'teacher_id',
        'class_session_id',
        'booking_id',
        'enrollment_id',
        'rating',
        'comment',
        'response',
        'responded_at',
        'is_hidden',
        'hidden_by',
        'hidden_reason',
        'is_reported',
        'report_reason',
        'edit_deadline',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'is_hidden' => 'boolean',
            'is_reported' => 'boolean',
            'edit_deadline' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function hider()
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }
}
