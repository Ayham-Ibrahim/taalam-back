<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $fillable = ['code', 'name_ar', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
