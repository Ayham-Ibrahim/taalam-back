<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'description_ar',
        'icon',
        'is_auto',
        'auto_document_type',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_auto' => 'boolean'];
    }

    public function awards()
    {
        return $this->hasMany(BadgeAward::class);
    }
}
