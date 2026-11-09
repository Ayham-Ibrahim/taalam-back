<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** بونص أو خصم لموظف في شهر (YYYY-MM) — يدخل في كشف راتب ذلك الشهر */
class EmployeeAdjustment extends Model
{
    public const TYPE_BONUS = 'bonus';

    public const TYPE_DEDUCTION = 'deduction';

    protected $fillable = [
        'employee_id',
        'month',
        'type',
        'amount',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
