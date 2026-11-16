<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SessionAttachment extends Model
{
    /** قرص خاص دائماً (RULE-06) — لا يُقدَّم الملف عبر رابط عام، فقط عبر رابط موقّع مؤقت. */
    public const DISK = 'local';

    /** حد ملفات كل رافع في الجلسة الواحدة — يمنع استنزاف التخزين برفع غير محدود. */
    public const MAX_PER_UPLOADER = 20;

    /** صلاحية رابط الفتح الموقّع بالدقائق */
    public const URL_TTL_MINUTES = 30;

    protected $fillable = [
        'class_session_id',
        'uploaded_by',
        'uploader_role',
        'title',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    protected $hidden = [
        'path',
    ];

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * ملفات المعلم/الأدمن يراها كل من يرى الجلسة؛ ملف الطالب يراه المعلم والأدمن
     * وصاحبه فقط — لا بقية طلاب جلسة المجموعة (حل واجب طالب لا يُكشف لزملائه).
     */
    public function scopeVisibleTo(Builder $query, User $user, ClassSession $session): Builder
    {
        if ($user->isAdmin() || $user->loadMissing('teacher')->teacher?->id === $session->teacher_id) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->where('uploader_role', '!=', 'student')
            ->orWhere('uploaded_by', $user->id)
        );
    }
}
