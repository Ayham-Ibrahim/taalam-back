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
        'grades',
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
            'grades' => 'array',
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
     *
     * لا يوجد حالياً أي إجراء فعلي في المنصة يُلغي جلسة واحدة بمعزل عن الحجز
     * كاملاً (status='cancelled' على class_sessions قيمة موجودة في المخطط
     * بلا أي كود يكتبها) — لكن إن حدث ذلك (تعديل يدوي، أو ميزة مستقبلية)، كانت
     * package_schedules (تاريخ الجدول فقط) منفصلة تماماً عن class_sessions
     * الفعلية المتولّدة منها، فتبقى الباقة الجماعية "متاحة للحجز" ظاهرياً رغم
     * أن جلستها الوحيدة الفعلية ملغاة — فينضم طالب جديد إليها فعلياً (راجع
     * BookingService::sessionsFor الذي يُعيد استخدام الجلسات المولَّدة سابقاً
     * دون أي فلترة على status). الفحص هنا يغلق هذه الفجوة: إن كانت الجلسات
     * الفعلية قد تولَّدت مسبقاً لهذه الباقة (حجز سابق)، يجب أن تبقى واحدة على
     * الأقل غير ملغاة.
     */
    public function isBookable(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->isIndividual()) {
            return true;
        }

        $hasFutureSchedule = $this->schedules()
            ->whereNotNull('date')
            ->whereDate('date', '>=', now()->toDateString())
            ->exists();

        if (! $hasFutureSchedule) {
            return false;
        }

        return ! $this->generatedSessionsAllCancelled();
    }

    /** نظير isBookable() على مستوى الاستعلام — لإخفاء الباقات المنتهية من قوائم الطالب والبحث. */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $q) => $q
                ->where('session_format', 'individual')
                ->orWhere(fn (Builder $group) => $group
                    ->whereHas('schedules', fn (Builder $s) => $s
                        ->whereNotNull('date')
                        ->whereDate('date', '>=', now()->toDateString())
                    )
                    ->where(fn (Builder $notAllCancelled) => $notAllCancelled
                        ->whereDoesntHave('bookings.sessions')
                        ->orWhereHas('bookings.sessions', fn (Builder $cs) => $cs->where('status', '!=', 'cancelled'))
                    )
                )
            );
    }

    private function generatedSessionsAllCancelled(): bool
    {
        $generated = $this->bookings()->whereHas('sessions');

        return $generated->exists()
            && ! $this->bookings()->whereHas('sessions', fn (Builder $q) => $q->where('status', '!=', 'cancelled'))->exists();
    }
}
