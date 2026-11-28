<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * محادثة واحدة لكل (طالب، معلم/داعم) — الطرفان حسابا مستخدمين. لا إغلاق: status
 * إما active أو blocked (بقرار إداري فقط). راجع ConversationService.
 */
class Conversation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'student_user_id',
        'provider_user_id',
        'status',
        'blocked_by',
        'blocked_at',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'blocked_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function studentUser()
    {
        return $this->belongsTo(User::class, 'student_user_id')->withTrashed();
    }

    public function providerUser()
    {
        return $this->belongsTo(User::class, 'provider_user_id')->withTrashed();
    }

    public function blocker()
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /** تشمل المحذوفة (في الاستعلام الفرعي والخارجي) كي تعرض القائمة "تم حذف هذه الرسالة" لا ما قبلها */
    public function latestMessage()
    {
        return $this->hasOne(Message::class)->ofMany(['id' => 'max'], fn ($q) => $q->withTrashed())->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasParticipant(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return (int) $this->student_user_id === $userId || (int) $this->provider_user_id === $userId;
    }

    public function otherParticipantId(User|int $user): int
    {
        $userId = $user instanceof User ? $user->id : $user;

        return (int) $this->student_user_id === $userId ? (int) $this->provider_user_id : (int) $this->student_user_id;
    }

    public function otherParticipant(User|int $user): ?User
    {
        $userId = $user instanceof User ? $user->id : $user;

        return (int) $this->student_user_id === $userId ? $this->providerUser : $this->studentUser;
    }

    public function scopeForUser($query, User|int $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where(fn ($q) => $q->where('student_user_id', $userId)->orWhere('provider_user_id', $userId));
    }

    /** المحادثة بين مستخدمين أياً كان ترتيبهما (للمرفقات: الرافع قد يكون الطالب أو المعلم) */
    public function scopeBetween($query, int $firstUserId, int $secondUserId)
    {
        return $query->where(fn ($q) => $q
            ->where(fn ($p) => $p->where('student_user_id', $firstUserId)->where('provider_user_id', $secondUserId))
            ->orWhere(fn ($p) => $p->where('student_user_id', $secondUserId)->where('provider_user_id', $firstUserId)));
    }

    /**
     * البحث باسم الطرف الآخر فقط (لا باسم المستخدم نفسه — وإلا طابقت كل محادثاته باسمه).
     * الطرف الآخر هو مقدّم الخدمة حين يكون المستخدم الطالب، والعكس. % و _ في نص البحث حرفية.
     */
    public function scopeSearchCounterpartName($query, int $userId, string $term)
    {
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn ($q) => $q
            ->where(fn ($s) => $s->where('student_user_id', $userId)
                ->whereHas('providerUser', fn ($u) => $u->where('name', 'like', $like)))
            ->orWhere(fn ($p) => $p->where('provider_user_id', $userId)
                ->whereHas('studentUser', fn ($u) => $u->where('name', 'like', $like))));
    }

    /**
     * غير المقروء = رسائل نصية من الطرف الآخر بلا read_at. رسائل النظام خارج العدّ:
     * read_at واحد لا يصلح لطرفين، وهي إعلامية لا تنتظر رداً.
     */
    public function scopeWithUnreadCountFor($query, int $userId)
    {
        return $query->withCount(['messages as unread_count' => fn ($q) => $q
            ->where('type', Message::TYPE_TEXT)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')]);
    }
}
