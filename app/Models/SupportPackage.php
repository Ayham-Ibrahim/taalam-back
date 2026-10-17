<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportPackage extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'currency' => 'AED',
    ];

    protected $fillable = [
        'academic_supporter_id',
        'subject_id',
        'tier',
        'description',
        'teacher_price',
        'platform_margin_percent',
        'student_price',
        'platform_revenue',
        'currency',
        'status',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'teacher_price' => 'decimal:2',
            'platform_margin_percent' => 'decimal:2',
            'student_price' => 'decimal:2',
            'platform_revenue' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function academicSupporter()
    {
        return $this->belongsTo(AcademicSupporter::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function purchases()
    {
        return $this->hasMany(SupportPackagePurchase::class);
    }
}
