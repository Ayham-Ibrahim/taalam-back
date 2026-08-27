<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use SoftDeletes;

    /** لا يكتمل التسجيل بدونها — إثبات هوية، شهادة أكاديمية، وشهادة/إثبات خبرة */
    public const REQUIRED_DOCUMENT_TYPES = ['identity', 'academic', 'experience'];

    protected $fillable = [
        'user_id',
        'teacher_type',
        'qualification',
        'experience_years',
        'bio',
        'intro_video_path',
        'intro_video_seconds',
        'display_name_en',
        'logo_path',
        'commercial_register',
        'website',
        'address',
        'city',
        'age_groups',
        'teaching_methods',
        'timezone',
        'max_daily_sessions',
        'status',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'no_show_count',
        'ranking_score',
    ];

    protected function casts(): array
    {
        return [
            'age_groups' => 'array',
            'teaching_methods' => 'array',
            'verified_at' => 'datetime',
            'rating_avg' => 'decimal:2',
            'completion_rate' => 'decimal:2',
            'profile_completeness' => 'decimal:2',
            'ranking_score' => 'decimal:2',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'teacher_subject');
    }

    public function curricula()
    {
        return $this->belongsToMany(Curriculum::class, 'teacher_curriculum');
    }

    public function languages()
    {
        return $this->belongsToMany(Language::class, 'teacher_language');
    }

    public function verificationDocuments()
    {
        return $this->hasMany(VerificationDocument::class);
    }

    public function badgeAwards()
    {
        return $this->hasMany(BadgeAward::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }

    public function availabilitySlots()
    {
        return $this->hasMany(AvailabilitySlot::class);
    }

    public function blackouts()
    {
        return $this->hasMany(TeacherBlackout::class);
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function isTrainingCenter(): bool
    {
        return $this->teacher_type === 'training_center';
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
