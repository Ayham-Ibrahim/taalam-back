<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stage extends Model
{
    protected $fillable = ['code', 'name_ar', 'education_type', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'subject_stage');
    }
}
