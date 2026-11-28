<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * الحذف ناعم دائماً: الطرف الآخر يرى "تم حذف هذه الرسالة" والنص يبقى محفوظاً للإدارة،
 * وكل تعديل يحفظ النص السابق في message_edits.
 */
class Message extends Model
{
    use SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_SYSTEM = 'system';

    /** مهلة التعديل والحذف من وقت الإرسال */
    public const EDIT_WINDOW_MINUTES = 30;

    public const MAX_LENGTH = 2000;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'type',
        'body',
        'meta',
        'read_at',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'read_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function edits()
    {
        return $this->hasMany(MessageEdit::class)->orderBy('edited_at');
    }

    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    public function editableUntil()
    {
        return $this->created_at->copy()->addMinutes(self::EDIT_WINDOW_MINUTES);
    }

    public function isWithinEditWindow(): bool
    {
        return now()->lt($this->editableUntil());
    }
}
