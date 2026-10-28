<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** سياسة الخصوصية / الشروط والأحكام — محتوى مُهيكَل بأقسام، معدّل من الأدمن فقط */
class LegalPage extends Model
{
    protected $fillable = ['slug', 'sections'];

    protected $casts = [
        'sections' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
