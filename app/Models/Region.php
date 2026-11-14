<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ولاية/إمارة/منطقة داخل دولة — تصنيف يديره الأدمن، تُجمَّع عليه تقارير التسويق */
class Region extends Model
{
    protected $fillable = ['country_id', 'code', 'name_ar', 'name_en', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }
}
