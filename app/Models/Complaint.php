<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    protected $fillable = [
        'reference',
        'student_id',
        'teacher_id',
        'filed_by_user_id',
        'booking_id',
        'class_session_id',
        'conversation_id',
        'reported_message_id',
        'category',
        'description',
        'status',
        'resolution_type',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'sla_due_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'sla_due_at' => 'datetime',
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

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function filer()
    {
        return $this->belongsTo(User::class, 'filed_by_user_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reportedMessage()
    {
        return $this->belongsTo(Message::class, 'reported_message_id')->withTrashed();
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
