<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stage extends Model
{
    protected $fillable = ['code', 'name_ar', 'education_type', 'grade_from', 'grade_to', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'grade_from' => 'integer', 'grade_to' => 'integer'];
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'subject_stage');
    }
}
