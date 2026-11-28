<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageEdit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'old_body',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function message()
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }
}
