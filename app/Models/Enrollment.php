<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'currency' => 'USD',
    ];

    protected $fillable = [
        'reference',
        'student_id',
        'course_id',
        'teacher_id',
        'amount_paid',
        'provider_amount',
        'platform_amount',
        'margin_percent_snapshot',
        'currency',
        'policy_accepted_at',
        'is_manual',
        'created_by_admin_id',
        'manual_reason',
        'status',
        'hold_expires_at',
        'confirmed_at',
        'withdrawn_at',
        'withdrawal_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'provider_amount' => 'decimal:2',
            'platform_amount' => 'decimal:2',
            'margin_percent_snapshot' => 'decimal:2',
            'policy_accepted_at' => 'datetime',
            'is_manual' => 'boolean',
            'hold_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * يحصر الاستعلام بما يحق للمستخدم رؤيته حسب دوره — الأدمن يرى الكل، المعلم/المركز
     * تسجيلات طلابه، الطالب تسجيلاته فقط.
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
