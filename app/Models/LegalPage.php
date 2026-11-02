<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** سياسة الخصوصية / الشروط والأحكام / سياسة الطالب والمعلم — محتوى مُهيكَل بأقسام، معدّل من الأدمن فقط */
class LegalPage extends Model
{
    public const STUDENT_POLICY = 'student-policy';

    public const TEACHER_POLICY = 'teacher-policy';

    protected $fillable = ['slug', 'sections'];

    /**
     * حقول دليل الموافقة على سياسة الدور لحظة التسجيل — النسخة هي updated_at الصفحة وقتها
     * (null إن لم تُنشأ الصفحة بعد، فتبقى لحظة الموافقة وحدها).
     *
     * @return array{policy_accepted_at: \Illuminate\Support\Carbon, policy_version: mixed}
     */
    public static function acceptanceFor(string $slug): array
    {
        return [
            'policy_accepted_at' => now(),
            'policy_version' => self::where('slug', $slug)->value('updated_at'),
        ];
    }

    protected $casts = [
        'sections' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
