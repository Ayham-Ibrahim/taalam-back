<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use SoftDeletes;

    /** لا يكتمل التسجيل بدونها — إثبات هوية، شهادة أكاديمية، وشهادة/إثبات خبرة */
    public const REQUIRED_DOCUMENT_TYPES = ['identity', 'academic', 'experience'];

    /** حد تعسفي معقول يمنع نمواً غير محدود لقائمة الفيديوهات — سهل التعديل لاحقاً إن لزم */
    public const MAX_VIDEOS = 6;

    /** نفس منطق MAX_VIDEOS تماماً — يمنع قائمة أسئلة شائعة غير منتهية */
    public const MAX_FAQS = 10;

    /** نفس المنطق أيضاً — سجل خبرات سابقة يديره المعلم بنفسه (عنوان + فترة نصية) */
    public const MAX_EXPERIENCES = 10;

    protected $fillable = [
        'user_id',
        'teacher_type',
        'qualification',
        'experience_years',
        'bio',
        'intro_video_path',
        'intro_video_seconds',
        'intro_youtube_id',
        'display_name_en',
        'logo_path',
        'commercial_register',
        'website',
        'address',
        'city',
        'age_groups',
        'teaching_methods',
        'exam_prep',
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
            'exam_prep' => 'array',
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

    public function videos()
    {
        return $this->hasMany(TeacherVideo::class)->orderBy('sort_order');
    }

    public function faqs()
    {
        return $this->hasMany(TeacherFaq::class)->orderBy('sort_order');
    }

    public function experiences()
    {
        return $this->hasMany(TeacherExperience::class)->orderBy('sort_order');
    }

    public function badgeAwards()
    {
        return $this->hasMany(BadgeAward::class);
    }

    /** الشارات الفعّالة حالياً فقط (غير ملغاة) — هذا ما يُعرَض علناً بملف المعلم. */
    public function activeBadgeAwards()
    {
        return $this->badgeAwards()->whereNull('revoked_at')->with('badge')->orderBy('granted_at');
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
