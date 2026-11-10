<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * كشف راتب موظف لشهر واحد. pending: يُعاد حسابه (الأساسي الحالي + البونصات − الخصومات) عند
 * كل تعديل. paid: لقطة نهائية مقفلة — لا تُضاف بونصات/خصومات لشهره بعدها.
 * المبالغ الأصلية بعملة الموظف (currency)، وأعمدة *_aed مقابلها بالدرهم بسعر exchange_rate لشهر الكشف.
 */
class EmployeeSalary extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected $attributes = [
        'currency' => 'AED',
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'employee_id',
        'month',
        'base_salary',
        'bonuses_total',
        'deductions_total',
        'net_amount',
        'currency',
        'exchange_rate',
        'base_salary_aed',
        'bonuses_total_aed',
        'deductions_total_aed',
        'net_amount_aed',
        'status',
        'paid_at',
        'paid_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'bonuses_total' => 'decimal:2',
            'deductions_total' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_salary_aed' => 'decimal:2',
            'bonuses_total_aed' => 'decimal:2',
            'deductions_total_aed' => 'decimal:2',
            'net_amount_aed' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** فلاتر كشف الرواتب المشتركة بين القائمة والتصدير: month أو from/to (YYYY-MM)، status، employee_id */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(! empty($filters['month']), fn ($q) => $q->where('month', $filters['month']))
            ->when(! empty($filters['from']), fn ($q) => $q->where('month', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->where('month', '<=', $filters['to']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', $filters['employee_id']));
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
