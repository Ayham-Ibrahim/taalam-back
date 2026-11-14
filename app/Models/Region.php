<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ولاية/إمارة الطالب — تصنيف يديره الأدمن، تُجمَّع عليه تقارير التسويق */
class Region extends Model
{
    protected $fillable = ['code', 'name_ar', 'name_en', 'country', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }
}
