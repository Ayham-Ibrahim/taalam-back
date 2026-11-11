<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تقييم جودة جلسة من الطالب — للأدمن فقط (لا يدخل في تقييم المعلم العام ولا ترتيبه).
 * كل معيار من 1 إلى 5.
 */
class SessionQualityRating extends Model
{
    public const CRITERIA = [
        'audio_video_quality',
        'connection_stability',
        'teaching_quality',
        'overall_rating',
        'benefit_level',
    ];

    protected $fillable = [
        'class_session_id',
        'student_id',
        'audio_video_quality',
        'connection_stability',
        'teaching_quality',
        'overall_rating',
        'benefit_level',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'audio_video_quality' => 'integer',
            'connection_stability' => 'integer',
            'teaching_quality' => 'integer',
            'overall_rating' => 'integer',
            'benefit_level' => 'integer',
        ];
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * رابط صفحة التقييم بالواجهة — نفسه للطالب بالإشعار وللمعلم بزر "نسخ الرابط". برمز
     * عشوائي (ClassSession::ensureQualityRatingToken) لا برقم الجلسة.
     */
    public static function frontendPath(string $token): string
    {
        return "/rate-session/{$token}";
    }
}
