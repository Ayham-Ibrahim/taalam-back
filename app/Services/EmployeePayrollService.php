<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\EmployeeSalary;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * رواتب الموظفين الشهرية. الشهر نص YYYY-MM — المقارنة النصية بين شهرين بهذا الشكل صحيحة ترتيبياً.
 * كشف pending يُعاد حسابه من الراتب الأساسي الحالي وتعديلات شهره عند كل تغيير؛ كشف paid لا يُمس.
 */
class EmployeePayrollService
{
    use LogsAuditEvents;

    public function create(array $data): Employee
    {
        $employee = Employee::create($data);

        $this->audit('employee.created', $employee, [], $employee->only(['name', 'job_title', 'base_salary']));

        return $employee;
    }

    /** تعديل الراتب الأساسي ينعكس على كشوفه غير المدفوعة — المدفوعة لقطة نهائية */
    public function update(Employee $employee, array $data): Employee
    {
        $old = $employee->only(array_keys($data));

        DB::transaction(function () use ($employee, $data) {
            $employee->update($data);

            if ($employee->wasChanged('base_salary') || $employee->wasChanged('currency')) {
                $employee->salaries()->where('status', EmployeeSalary::STATUS_PENDING)->pluck('month')
                    ->each(fn (string $month) => $this->recalculate($employee, $month));
            }
        });

        $this->audit('employee.updated', $employee, $old, $employee->only(array_keys($data)));

        return $employee->refresh();
    }

    /** موظف له كشوف رواتب لا يُحذف كي لا يضيع السجل المالي — يُعطَّل (is_active=false) بدلاً من ذلك */
    public function delete(Employee $employee): void
    {
        if ($employee->salaries()->exists()) {
            throw ValidationException::withMessages([
                'employee' => 'لا يمكن حذف موظف له كشوف رواتب، قم بتعطيله بدلاً من ذلك',
            ]);
        }

        $this->audit('employee.deleted', $employee, $employee->only(['name', 'job_title', 'base_salary']));

        $employee->delete();
    }

    public function addAdjustment(Employee $employee, array $data, User $by): EmployeeAdjustment
    {
        $this->ensureMonthOpen($employee, $data['month']);

        $adjustment = DB::transaction(function () use ($employee, $data, $by) {
            $adjustment = $employee->adjustments()->create([...$data, 'created_by' => $by->id]);
            $this->recalculate($employee, $data['month']);

            return $adjustment;
        });

        $this->audit("employee.{$adjustment->type}_added", $employee, [], $adjustment->only(['month', 'type', 'amount', 'reason']));

        return $adjustment;
    }

    public function deleteAdjustment(EmployeeAdjustment $adjustment): void
    {
        $employee = $adjustment->employee;
        $this->ensureMonthOpen($employee, $adjustment->month);

        DB::transaction(function () use ($adjustment, $employee) {
            $adjustment->delete();
            $this->recalculate($employee, $adjustment->month);
        });

        $this->audit("employee.{$adjustment->type}_deleted", $employee, $adjustment->only(['month', 'type', 'amount', 'reason']));
    }

    /**
     * يولّد كشف الشهر لكل موظف نشط عُيّن قبل نهاية الشهر وليس له كشف فيه، ويعيد حساب الكشوف
     * غير المدفوعة الموجودة. آمن التكرار: unique(employee_id, month) يمنع كشفين لنفس الشهر.
     *
     * @return int عدد الكشوف المُنشأة
     */
    public function generateMonth(string $month): int
    {
        $endOfMonth = Carbon::createFromFormat('!Y-m', $month)->endOfMonth()->toDateString();

        $employees = Employee::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('hired_at')->orWhereDate('hired_at', '<=', $endOfMonth))
            ->get();

        $created = 0;

        foreach ($employees as $employee) {
            DB::transaction(function () use ($employee, $month, &$created) {
                $salary = EmployeeSalary::firstOrCreate(
                    ['employee_id' => $employee->id, 'month' => $month],
                    ['base_salary' => $employee->base_salary, 'net_amount' => $employee->base_salary, 'currency' => $employee->currency],
                );

                if ($salary->wasRecentlyCreated) {
                    $created++;
                }

                $this->recalculate($employee, $month);
            });
        }

        return $created;
    }

    public function markPaid(EmployeeSalary $salary, User $by, ?string $notes = null): EmployeeSalary
    {
        $salary = DB::transaction(function () use ($salary, $by, $notes) {
            $salary = EmployeeSalary::lockForUpdate()->findOrFail($salary->id);

            if ($salary->isPaid()) {
                throw ValidationException::withMessages(['salary' => 'هذا الراتب مدفوع مسبقاً']);
            }

            $salary->update([
                'status' => EmployeeSalary::STATUS_PAID,
                'paid_at' => now(),
                'paid_by' => $by->id,
                'notes' => $notes ?? $salary->notes,
            ]);

            return $salary;
        });

        $this->audit('employee_salary.paid', $salary, [], $salary->only(['employee_id', 'month', 'net_amount']));

        return $salary;
    }

    /**
     * التقرير الشامل لفترة أشهر (from/to بصيغة YYYY-MM، كلاهما اختياري): صف لكل موظف بمجاميع
     * كشوفه، وتفصيل شهري، ومجاميع عامة. مبني على الكشوف المولَّدة فقط.
     */
    public function report(?string $from, ?string $to, ?int $employeeId): array
    {
        $salaries = EmployeeSalary::query()
            ->with('employee:id,name,job_title,is_active')
            ->when($from, fn ($q) => $q->where('month', '>=', $from))
            ->when($to, fn ($q) => $q->where('month', '<=', $to))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderBy('month')
            ->get();

        $rows = $salaries->groupBy('employee_id')->map(function ($items) {
            $employee = $items->first()->employee;
            $paid = $items->where('status', EmployeeSalary::STATUS_PAID);

            return [
                'employeeId' => $employee->id,
                'employeeName' => $employee->name,
                'jobTitle' => $employee->job_title,
                'isActive' => $employee->is_active,
                'monthsCount' => $items->count(),
                ...$this->sums($items),
                'paidAmount' => round((float) $paid->sum('net_amount'), 2),
                'outstandingAmount' => round((float) $items->where('status', EmployeeSalary::STATUS_PENDING)->sum('net_amount'), 2),
            ];
        })->sortBy('employeeName')->values();

        $months = $salaries->groupBy('month')->map(fn ($items, $month) => [
            'month' => $month,
            'employeesCount' => $items->count(),
            ...$this->sums($items),
            'paidAmount' => round((float) $items->where('status', EmployeeSalary::STATUS_PAID)->sum('net_amount'), 2),
        ])->values();

        return [
            'filters' => ['from' => $from, 'to' => $to, 'employeeId' => $employeeId],
            'summary' => [
                'activeEmployees' => Employee::where('is_active', true)->count(),
                'currentMonthlyBasePayroll' => round((float) Employee::where('is_active', true)->sum('base_salary'), 2),
                ...$this->sums($salaries),
                'paidAmount' => round((float) $salaries->where('status', EmployeeSalary::STATUS_PAID)->sum('net_amount'), 2),
                'outstandingAmount' => round((float) $salaries->where('status', EmployeeSalary::STATUS_PENDING)->sum('net_amount'), 2),
            ],
            'rows' => $rows,
            'months' => $months,
        ];
    }

    private function sums($items): array
    {
        return [
            'baseTotal' => round((float) $items->sum('base_salary'), 2),
            'bonusesTotal' => round((float) $items->sum('bonuses_total'), 2),
            'deductionsTotal' => round((float) $items->sum('deductions_total'), 2),
            'netTotal' => round((float) $items->sum('net_amount'), 2),
        ];
    }

    /** يعيد حساب كشف الشهر إن وُجد وكان غير مدفوع — شهر بلا كشف تُحسب تعديلاته عند توليده */
    private function recalculate(Employee $employee, string $month): void
    {
        $salary = EmployeeSalary::where('employee_id', $employee->id)->where('month', $month)->lockForUpdate()->first();

        if (! $salary || $salary->isPaid()) {
            return;
        }

        $totals = EmployeeAdjustment::where('employee_id', $employee->id)->where('month', $month)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'bonus' THEN amount END), 0) AS bonuses")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'deduction' THEN amount END), 0) AS deductions")
            ->first();

        $base = (float) $employee->base_salary;
        $bonuses = (float) $totals->bonuses;
        $deductions = (float) $totals->deductions;

        $salary->update([
            'base_salary' => $base,
            'bonuses_total' => $bonuses,
            'deductions_total' => $deductions,
            'net_amount' => round($base + $bonuses - $deductions, 2),
            'currency' => $employee->currency,
        ]);
    }

    private function ensureMonthOpen(Employee $employee, string $month): void
    {
        $paid = EmployeeSalary::where('employee_id', $employee->id)->where('month', $month)
            ->where('status', EmployeeSalary::STATUS_PAID)->exists();

        if ($paid) {
            throw ValidationException::withMessages([
                'month' => 'راتب هذا الشهر مدفوع مسبقاً، لا يمكن تعديل بونصاته أو خصوماته',
            ]);
        }
    }
}
