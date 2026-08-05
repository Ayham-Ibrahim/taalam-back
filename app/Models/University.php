<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class University extends Model
{
    protected $fillable = ['name_ar', 'name_en', 'country', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
