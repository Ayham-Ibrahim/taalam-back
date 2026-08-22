<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'currency' => 'USD',
    ];

    protected $fillable = [
        'reference',
        'student_id',
        'teacher_id',
        'package_id',
        'amount_paid',
        'teacher_amount',
        'platform_amount',
        'margin_percent_snapshot',
        'currency',
        'sessions_total',
        'sessions_used',
        'sessions_remaining',
        'expires_at',
        'frozen_until',
        'extension_days',
        'requested_date',
        'requested_start_time',
        'requested_slots',
        'requested_timezone',
        'policy_accepted_at',
        'is_manual',
        'created_by_admin_id',
        'manual_reason',
        'status',
        'hold_expires_at',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'teacher_amount' => 'decimal:2',
            'platform_amount' => 'decimal:2',
            'margin_percent_snapshot' => 'decimal:2',
            'expires_at' => 'date',
            'frozen_until' => 'date',
            'requested_date' => 'date:Y-m-d',
            'requested_slots' => 'array',
            'policy_accepted_at' => 'datetime',
            'is_manual' => 'boolean',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    /**
     * الجلسات التي "تملكها" هذه الحجزة مباشرة (booking_id). في باقة المجموعة، هذا
     * ينطبق فقط على أول حجز أنشأ الجلسات — انضمامات لاحقة تُسجَّل عبر session_attendees
     * فقط دون أن تصبح "مالكة" لأي جلسة. استخدم attendedSessions() لعرض جلسات الطالب الفعلية.
     */
    public function sessions()
    {
        return $this->hasMany(ClassSession::class);
    }

    /**
     * كل الجلسات التي يحضرها صاحب هذه الحجزة فعلياً (عبر session_attendees) — الأصحّ
     * لعرضها للطالب، بصرف النظر عمّن "يملك" سجل class_sessions.
     */
    public function attendedSessions()
    {
        return $this->belongsToMany(ClassSession::class, 'session_attendees', 'booking_id', 'class_session_id')
            ->withPivot(['attendance', 'joined_at', 'left_at', 'duration_minutes', 'deducted_from_balance']);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function rescheduleRequests()
    {
        return $this->hasMany(RescheduleRequest::class);
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class);
    }

    /**
     * يحصر الاستعلام بما يحق للمستخدم رؤيته حسب دوره — الأدمن يرى الكل، المعلم
     * حجوزات طلابه، الطالب حجوزاته فقط. مركزي كي لا يتكرر منطق الأدوار في كل Controller.
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
            return $query->where('student_id', $user->loadMissing('student')->student?->id ?? 0);
        }

        return $query->whereRaw('1 = 0');
    }
}
