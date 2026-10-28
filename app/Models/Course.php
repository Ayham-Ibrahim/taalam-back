<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Course extends Model
{
    use SoftDeletes;

    /** الحالات التي تظهر فيها الدورة للطلاب (نفس فحص CourseService عند التسجيل) */
    public const PUBLIC_STATUSES = ['active', 'full', 'in_progress'];

    protected $attributes = [
        'currency' => 'AED',
        'session_duration_min' => 60,
    ];

    protected $fillable = [
        'teacher_id',
        'schedule_timezone',
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

    /**
     * يوازي Package::isBookable() — الدورة تنتهي فعلياً بانتهاء end_date حتى لو
     * بقيت status='active' (لا شيء يبدّل الحالة تلقائياً بمرور الوقت). لا شيء
     * كان يمنع تسجيل طالب في دورة منتهية فعلياً قبل هذا.
     */
    public function isBookable(): bool
    {
        return $this->status === 'active' && ! $this->hasEnded();
    }

    /**
     * الدورة منتهية إن مضى end_date، أو بدأت كل جلساتها المعلنة ولو لم يمضِ end_date
     * بعد (الجدول قد يكتمل قبل نهاية الفترة) — لا يبقى ما يُسجَّل فيه. نفس تعريف scopeNotEnded.
     */
    public function hasEnded(): bool
    {
        if ($this->end_date !== null && $this->end_date->toDateString() < now()->toDateString()) {
            return true;
        }

        return $this->total_sessions > 0 && $this->elapsedSessions()->count() >= $this->total_sessions;
    }

    /**
     * مقابل hasEnded() على مستوى الاستعلام. دورة لم تُنشأ جلساتها بعد (لا تسجيل فيها)
     * يُحكم عليها بـ end_date وحده — حساب مواعيدها المخطَّطة غير ممكن في SQL.
     */
    public function scopeNotEnded(Builder $query): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()))
            ->where(fn ($q) => $q
                ->whereDoesntHave('sessions')
                ->orWhereHas('sessions', fn ($s) => $s
                    ->whereColumn('class_sessions.sequence_no', '<=', 'courses.total_sessions')
                    ->where('scheduled_at', '>', now())));
    }

    /**
     * مواعيد جلسات الدورة المخطَّطة من start_date حتى end_date وفق course_schedules —
     * أول total_sessions موعداً فقط بالترتيب الزمني (الجدول الأسبوعي على كامل الفترة
     * قد يتسع لأكثر من العدد المعلن والمدفوع ثمنه). بتوقيت النظام.
     *
     * @return Collection<int, Carbon>
     */
    public function plannedSessionDates(): Collection
    {
        $schedules = $this->relationLoaded('schedules') ? $this->schedules : $this->schedules()->get();

        if ($schedules->isEmpty() || ! $this->start_date || ! $this->end_date) {
            return collect();
        }

        $dates = [];
        $cursor = $this->start_date->copy();

        while ($cursor->lessThanOrEqualTo($this->end_date)) {
            foreach ($schedules as $schedule) {
                if ((int) $cursor->dayOfWeek === (int) $schedule->day_of_week) {
                    // start_time أُدخِل بمنطقة المركز وقت إنشاء الدورة (schedule_timezone) — لا UTC ساذجة
                    $dates[] = Carbon::parse($cursor->toDateString(), $this->schedule_timezone ?? 'UTC')
                        ->setTimeFromTimeString($schedule->start_time)
                        ->setTimezone(config('app.timezone'));
                }
            }

            $cursor->addDay();
        }

        return collect($dates)->sortBy(fn (Carbon $d) => $d->getTimestamp())->take($this->total_sessions)->values();
    }

    /**
     * جلسات الدورة التي بدأ موعدها فعلاً — لا تُعوَّض لمن يسجّل بعدها. من جلسات
     * class_sessions إن أُنشئت (عند أول تسجيل)، وإلا من المواعيد المخطَّطة.
     *
     * @return Collection<int, array{sequence_no: int, scheduled_at: Carbon}>
     */
    public function elapsedSessions(): Collection
    {
        $sessions = $this->relationLoaded('sessions') ? $this->sessions : $this->sessions()->get();

        // take(total_sessions): دورات قديمة وُلِّدت لها جلسات أكثر من العدد المعلن قبل تقييد التوليد به
        $all = $sessions->isNotEmpty()
            ? $sessions->sortBy('sequence_no')->take($this->total_sessions)->map(fn (ClassSession $s) => ['sequence_no' => $s->sequence_no, 'scheduled_at' => $s->scheduled_at])
            : $this->plannedSessionDates()->map(fn (Carbon $d, int $i) => ['sequence_no' => $i + 1, 'scheduled_at' => $d]);

        $now = now();

        return $all->filter(fn (array $s) => $s['scheduled_at']->lte($now))->values();
    }
}
