<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** موظف إداري بلا حساب دخول — راتبه الشهري عبر EmployeeSalary وبونصاته/خصوماته عبر EmployeeAdjustment */
class Employee extends Model
{
    protected $attributes = [
        'currency' => 'AED',
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'job_title',
        'phone',
        'email',
        'base_salary',
        'currency',
        'hired_at',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'hired_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function adjustments()
    {
        return $this->hasMany(EmployeeAdjustment::class);
    }

    public function salaries()
    {
        return $this->hasMany(EmployeeSalary::class);
    }
}
