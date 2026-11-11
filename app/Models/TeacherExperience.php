<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherExperience extends Model
{
    /** خبرة سابقة (الافتراضي) */
    public const KIND_EXPERIENCE = 'experience';

    /** "الدورات المتاحة" للمدرس الجامعي — اسم الدورة ووصفها فقط */
    public const KIND_OFFERED_COURSE = 'offered_course';

    protected $fillable = [
        'teacher_id',
        'kind',
        'title',
        'company',
        'period',
        'location',
        'description',
        'sort_order',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * مدخل "دورة متاحة" (اسم + وصف بلا جهة/موقع/فترة): كل مدخلات المركز التدريبي، ومدخلات
     * offered_course للمدرس الجامعي.
     */
    public function isOfferedCourse(): bool
    {
        return $this->kind === self::KIND_OFFERED_COURSE || (bool) $this->teacher?->isTrainingCenter();
    }
}
