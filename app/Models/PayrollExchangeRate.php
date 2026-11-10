<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** سعر صرف عملة رواتب مقابل الدرهم لشهر: 1 وحدة من currency = rate_to_aed درهم. الدرهم نفسه بلا صف (سعره 1) */
class PayrollExchangeRate extends Model
{
    protected $fillable = [
        'month',
        'currency',
        'rate_to_aed',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_to_aed' => 'decimal:8',
        ];
    }
}
