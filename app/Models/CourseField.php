<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseField extends Model
{
    protected $fillable = ['code', 'name_ar', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
