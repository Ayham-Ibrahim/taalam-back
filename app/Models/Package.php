<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use SoftDeletes;

    /**
     * يعكس افتراضي عمود currency في قاعدة البيانات — بدونها يبقى null في الذاكرة
     * مباشرة بعد create() (Eloquent لا يُعيد قراءة القيم الافتراضية من الخادم)،
     * ما يكسر أي كود يقرأ $model->currency في نفس الطلب (كخدمات الحجز).
     */
    protected $attributes = [
        'currency' => 'USD',
        'session_duration_min' => 60,
    ];

    protected $fillable = [
        'teacher_id',
        'schedule_timezone',
        'title',
        'description',
        'subject_id',
        'session_format',
        'capacity',
        'enrolled_count',
        'sessions_count',
        'session_duration_min',
        'validity_days',
        'teacher_price',
        'platform_margin_percent',
        'student_price',
        'platform_revenue',
        'currency',
        'discount_percent',
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
            'discount_percent' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
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
        return $this->belongsToMany(Curriculum::class, 'package_curriculum');
    }

    public function stages()
    {
        return $this->belongsToMany(Stage::class, 'package_stage');
    }

    public function schedules()
    {
        return $this->hasMany(PackageSchedule::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function isIndividual(): bool
    {
        return $this->session_format === 'individual';
    }

    /**
     * الباقة الجماعية جدولها تواريخ ثابتة اختارها المعلم — بعد مرور آخرها لم يبقَ
     * فيها موعد جلسة قابل للانضمام (كل جلساتها في الماضي)، فهي "منتهية" فعلياً حتى
     * لو بقيت status='active'. الفردية لا جدول تواريخ لها (الطالب يختار مواعيد
     * مستقبلية لاحقاً) فلا تنتهي بهذا المعنى.
     */
    public function isBookable(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->isIndividual()) {
            return true;
        }

        return $this->schedules()
            ->whereNotNull('date')
            ->whereDate('date', '>=', now()->toDateString())
            ->exists();
    }

    /** نظير isBookable() على مستوى الاستعلام — لإخفاء الباقات المنتهية من قوائم الطالب والبحث. */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $q) => $q
                ->where('session_format', 'individual')
                ->orWhereHas('schedules', fn (Builder $s) => $s
                    ->whereNotNull('date')
                    ->whereDate('date', '>=', now()->toDateString())
                )
            );
    }
}
