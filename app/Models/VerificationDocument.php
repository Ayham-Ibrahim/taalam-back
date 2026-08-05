<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationDocument extends Model
{
    /**
     * القرص الذي تُخزَّن عليه ملفات التوثيق. خاص دائماً (RULE-06) — لا يُقدَّم عبر رابط عام.
     * التبديل إلى S3 حقيقي لاحقاً هو تغيير قيمة هذا الثابت وضبط بيانات AWS، دون أي تغيير آخر.
     */
    public const DISK = 'local';

    protected $fillable = [
        'teacher_id',
        'type',
        's3_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
