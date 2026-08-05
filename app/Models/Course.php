<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'currency' => 'USD',
        'session_duration_min' => 60,
    ];

    protected $fillable = [
        'teacher_id',
        'title',
        'description',
        'course_field_id',
        'subject_id',
        'level',
        'delivery_mode',
        'location',
        'start_date',
        'end_date',
        'total_sessions',
        'session_duration_min',
        'total_hours',
        'max_seats',
        'enrolled_count',
        'provider_price',
        'pricing_mode',
        'platform_margin_percent',
        'student_price',
        'platform_revenue',
        'currency',
        'has_certificate',
        'certificate_type',
        'certificate_issuer',
        'certificate_requirements',
        'requires_laptop',
        'materials_included',
        'has_practical_exercises',
        'sessions_recorded',
        'prerequisites',
        'cancellation_policy',
        'status',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'provider_price' => 'decimal:2',
            'platform_margin_percent' => 'decimal:2',
            'student_price' => 'decimal:2',
            'platform_revenue' => 'decimal:2',
            'has_certificate' => 'boolean',
            'requires_laptop' => 'boolean',
            'materials_included' => 'boolean',
            'has_practical_exercises' => 'boolean',
            'sessions_recorded' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function courseField()
    {
        return $this->belongsTo(CourseField::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function curricula()
    {
        return $this->belongsToMany(Curriculum::class, 'course_curriculum');
    }

    public function schedules()
    {
        return $this->hasMany(CourseSchedule::class);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function sessions()
    {
        return $this->hasMany(ClassSession::class);
    }
}
