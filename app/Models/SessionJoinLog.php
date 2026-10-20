<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** سجل انضمام لغرفة جلسة — للعرض على الأدمن فقط، راجع SessionJoinLogService */
class SessionJoinLog extends Model
{
    protected $fillable = [
        'class_session_id',
        'user_id',
        'role',
        'student_id',
        'first_joined_at',
        'last_joined_at',
        'join_count',
    ];

    protected function casts(): array
    {
        return [
            'first_joined_at' => 'datetime',
            'last_joined_at' => 'datetime',
        ];
    }

    private static ?Carbon $trackingStartedAt = null;

    /**
     * لحظة بدء التسجيل = إنشاء الجدول نفسه (migration). جلسة بدأت قبلها لا سجل لها
     * إطلاقاً — غياب سجلها لا يعني أن أحداً لم يحضر، فلا تُعرض كـ"لم يحضر".
     */
    public static function trackingStartedAt(): ?Carbon
    {
        if (self::$trackingStartedAt === null) {
            // CREATE_TIME بتوقيت خادم MySQL لا بتوقيت التطبيق — UNIX_TIMESTAMP يحوّله بدقة
            $created = DB::table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', (new self)->getTable())
                ->value(DB::raw('UNIX_TIMESTAMP(CREATE_TIME)'));

            self::$trackingStartedAt = $created ? Carbon::createFromTimestamp($created) : null;
        }

        return self::$trackingStartedAt;
    }

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
