<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClassSession extends Model
{
    protected $fillable = [
        'booking_id',
        'course_id',
        'teacher_id',
        'sequence_no',
        'scheduled_at',
        'duration_min',
        'original_scheduled_at',
        'bbb_meeting_id',
        'bbb_attendee_pw',
        'bbb_moderator_pw',
        'join_url_student',
        'join_url_teacher',
        'reminder_sent_at',
        'recording_url',
        'status',
        'started_at',
        'ended_at',
        'cancellation_reason',
        'is_makeup',
        'makeup_for_session_id',
        'teacher_notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'original_scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'is_makeup' => 'boolean',
        ];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function attendees()
    {
        return $this->hasMany(SessionAttendee::class);
    }

    public function makeupForSession()
    {
        return $this->belongsTo(ClassSession::class, 'makeup_for_session_id');
    }

    public function rescheduleRequests()
    {
        return $this->hasMany(RescheduleRequest::class, 'class_session_id');
    }

    /**
     * لا عمود student_id مباشر على الجدول (جلسة المجموعة لها طلاب متعددون) —
     * رؤية الطالب تُحدَّد عبر session_attendees. الأدمن يرى الكل، المعلم بـ teacher_id.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isTeacher()) {
            return $query->where('teacher_id', $user->loadMissing('teacher')->teacher?->id ?? 0);
        }

        if ($user->isStudent()) {
            $studentId = $user->loadMissing('student')->student?->id ?? 0;

            return $query->whereHas('attendees', fn ($q) => $q->where('student_id', $studentId));
        }

        return $query->whereRaw('1 = 0');
    }
}
