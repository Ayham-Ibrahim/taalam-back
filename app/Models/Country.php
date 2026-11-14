<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** دولة الطالب — المستوى الأعلى للموقع (دولة ← ولاية)، تصنيف يديره الأدمن */
class Country extends Model
{
    protected $fillable = ['code', 'name_ar', 'name_en', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function regions()
    {
        return $this->hasMany(Region::class);
    }
}
