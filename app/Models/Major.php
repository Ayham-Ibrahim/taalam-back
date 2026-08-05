<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Major extends Model
{
    protected $fillable = ['name_ar', 'name_en', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
