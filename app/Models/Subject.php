<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = ['code', 'name_ar', 'name_en', 'education_type', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function stages()
    {
        return $this->belongsToMany(Stage::class, 'subject_stage');
    }
}
