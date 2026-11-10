<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Models\EmployeeSalary;
use App\Models\PayrollExchangeRate;
use App\Models\User;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * رواتب الموظفين الشهرية. الشهر نص YYYY-MM — المقارنة النصية بين شهرين بهذا الشكل صحيحة ترتيبياً.
 * كشف pending يُعاد حسابه من الراتب الأساسي الحالي وتعديلات شهره عند كل تغيير؛ كشف paid لا يُمس.
 * كل كشف يُحوَّل للدرهم بسعر صرف شهره (PayrollExchangeRate) وتُجمع التقارير بالدرهم فقط —
 * جمع المبالغ الأصلية بين موظفين بعملات مختلفة بلا معنى.
 */
class EmployeePayrollService
{
    use LogsAuditEvents;

    public function create(array $data): Employee
    {
        $employee = Employee::create($data);

        $this->audit('employee.created', $employee, [], $employee->only(['name', 'job_title', 'base_salary', 'currency']));

        return $employee;
    }

    /** تعديل الراتب الأساسي أو العملة ينعكس على كشوفه غير المدفوعة — المدفوعة لقطة نهائية */
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
     * $rates (عملة => سعرها بالدرهم) تُحفظ للشهر أولاً، والعملة الناقصة تأخذ آخر سعر محفوظ.
     *
     * @return int عدد الكشوف المُنشأة
     */
    public function generateMonth(string $month, array $rates = [], ?User $by = null): int
    {
        $endOfMonth = Carbon::createFromFormat('!Y-m', $month)->endOfMonth()->toDateString();

        $employees = Employee::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('hired_at')->orWhereDate('hired_at', '<=', $endOfMonth))
            ->get();

        // حفظ الأسعار والتحقق من توفر سعر لكل عملة قبل إنشاء أي كشف — معاً، كي لا يبقى سعر محفوظ
        // من توليد رُفض، ولا يتوقف التوليد في منتصفه
        DB::transaction(function () use ($month, $rates, $by, $employees) {
            $this->storeRates($month, $rates, $by);
            $employees->pluck('currency')->unique()->each(fn (string $currency) => $this->rateFor($month, $currency));
        });

        $created = 0;

        foreach ($employees as $employee) {
            DB::transaction(function () use ($employee, $month, &$created) {
                // المبالغ بالدرهم وسعر الصرف يملؤها recalculate مباشرة بعد الإنشاء
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

    /** أسعار شهر لكل عملة أجنبية: المحفوظ له، وإلا آخر سعر محفوظ قبله كاقتراح (saved=false) */
    public function ratesFor(string $month): array
    {
        $saved = PayrollExchangeRate::where('month', $month)->pluck('rate_to_aed', 'currency');

        return collect($this->foreignCurrencies())->mapWithKeys(function (string $currency) use ($month, $saved) {
            $rate = $saved[$currency] ?? $this->latestRate($currency, $month) ?? $this->latestRate($currency);

            return [$currency => [
                'rate' => $rate !== null ? (float) $rate : null,
                'saved' => isset($saved[$currency]),
            ]];
        })->all();
    }

    /** يحفظ أسعار شهر ويعيد حساب كشوفه غير المدفوعة بها — مرفوض إن دُفع أحد كشوفه (storeRates) */
    public function updateRates(string $month, array $rates, User $by): void
    {
        $old = PayrollExchangeRate::where('month', $month)->pluck('rate_to_aed', 'currency')->map(fn ($r) => (float) $r)->all();

        DB::transaction(function () use ($month, $rates, $by) {
            $this->storeRates($month, $rates, $by);

            EmployeeSalary::with('employee')->where('month', $month)->where('status', EmployeeSalary::STATUS_PENDING)->get()
                ->each(fn (EmployeeSalary $salary) => $this->recalculate($salary->employee, $month));
        });

        $this->audit('payroll.exchange_rates_updated', null, $old, ['month' => $month, ...$rates]);
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

        $this->audit('employee_salary.paid', $salary, [], $salary->only(['employee_id', 'month', 'net_amount', 'currency', 'exchange_rate', 'net_amount_aed']));

        return $salary;
    }

    /**
     * التقرير الشامل لفترة أشهر (from/to بصيغة YYYY-MM، كلاهما اختياري): صف لكل موظف بمجاميع
     * كشوفه، وتفصيل شهري، ومجاميع عامة. مبني على الكشوف المولَّدة فقط، وكل المجاميع بالدرهم.
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
            $currencies = $items->pluck('currency')->unique();

            return [
                'employeeId' => $employee->id,
                'employeeName' => $employee->name,
                'jobTitle' => $employee->job_title,
                'isActive' => $employee->is_active,
                'monthsCount' => $items->count(),
                ...$this->sums($items),
                // المبالغ بعملة الموظف — فقط إن كانت كل كشوفه بعملة واحدة، وإلا لا يصح جمعها
                'currency' => $currencies->count() === 1 ? $currencies->first() : null,
                'original' => $currencies->count() === 1 ? $this->sums($items, '') : null,
            ];
        })->sortBy('employeeName')->values();

        $rates = PayrollExchangeRate::whereIn('month', $salaries->pluck('month')->unique())->get()->groupBy('month');

        $months = $salaries->groupBy('month')->map(fn ($items, $month) => [
            'month' => $month,
            'employeesCount' => $items->count(),
            ...$this->sums($items),
            'rates' => ($rates[$month] ?? collect())->mapWithKeys(fn ($r) => [$r->currency => (float) $r->rate_to_aed]),
        ])->values();

        return [
            'filters' => ['from' => $from, 'to' => $to, 'employeeId' => $employeeId],
            'currency' => Employee::BASE_CURRENCY,
            'summary' => [
                'activeEmployees' => Employee::where('is_active', true)->count(),
                'currentMonthlyBasePayroll' => $this->currentMonthlyBasePayroll(),
                ...$this->sums($salaries),
            ],
            'rows' => $rows,
            'months' => $months,
        ];
    }

    /** مجموع الرواتب الأساسية للموظفين النشطين بالدرهم بأحدث سعر محفوظ — عملة بلا أي سعر لا تُحتسب */
    public function currentMonthlyBasePayroll(): float
    {
        $total = Employee::where('is_active', true)
            ->selectRaw('currency, SUM(base_salary) AS total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->reduce(function (float $sum, $amount, string $currency) {
                $rate = $currency === Employee::BASE_CURRENCY ? 1 : $this->latestRate($currency);

                return $rate === null ? $sum : $sum + (float) $amount * (float) $rate;
            }, 0.0);

        return round($total, 2);
    }

    /** المجاميع بالدرهم افتراضياً ($suffix='_aed')، أو بالعملة الأصلية ($suffix='') */
    private function sums($items, string $suffix = '_aed'): array
    {
        $sum = fn (string $column, $rows = null) => round((float) ($rows ?? $items)->sum($column.$suffix), 2);

        return [
            'baseTotal' => $sum('base_salary'),
            'bonusesTotal' => $sum('bonuses_total'),
            'deductionsTotal' => $sum('deductions_total'),
            'netTotal' => $sum('net_amount'),
            'paidAmount' => $sum('net_amount', $items->where('status', EmployeeSalary::STATUS_PAID)),
            'outstandingAmount' => $sum('net_amount', $items->where('status', EmployeeSalary::STATUS_PENDING)),
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
        $rate = $this->rateFor($month, $employee->currency);

        // الصافي بالدرهم = مجموع مكوناته المقرّبة، كي تتطابق أعمدة الكشف عند جمعها
        $baseAed = round($base * $rate, 2);
        $bonusesAed = round($bonuses * $rate, 2);
        $deductionsAed = round($deductions * $rate, 2);

        $salary->update([
            'base_salary' => $base,
            'bonuses_total' => $bonuses,
            'deductions_total' => $deductions,
            'net_amount' => round($base + $bonuses - $deductions, 2),
            'currency' => $employee->currency,
            'exchange_rate' => $rate,
            'base_salary_aed' => $baseAed,
            'bonuses_total_aed' => $bonusesAed,
            'deductions_total_aed' => $deductionsAed,
            'net_amount_aed' => round($baseAed + $bonusesAed - $deductionsAed, 2),
        ]);
    }

    /**
     * سعر عملة مقابل الدرهم لشهر. شهر بلا سعر محفوظ لهذه العملة يأخذ آخر سعر محفوظ ويُثبَّت له،
     * كي تُحسب كل كشوف الشهر بسعر واحد. عملة لم يُدخل لها سعر قط ترفض العملية.
     */
    private function rateFor(string $month, string $currency): float
    {
        if ($currency === Employee::BASE_CURRENCY) {
            return 1.0;
        }

        $rate = PayrollExchangeRate::where('month', $month)->where('currency', $currency)->value('rate_to_aed')
            ?? $this->latestRate($currency, $month)
            ?? $this->latestRate($currency);

        if ($rate === null) {
            throw ValidationException::withMessages([
                "rates.{$currency}" => "أدخل سعر صرف {$currency} مقابل الدرهم لشهر {$month}",
            ]);
        }

        PayrollExchangeRate::firstOrCreate(['month' => $month, 'currency' => $currency], ['rate_to_aed' => $rate]);

        return (float) $rate;
    }

    /** آخر سعر محفوظ لعملة — حتى شهر معيّن (شاملاً) إن مُرّر */
    private function latestRate(string $currency, ?string $upToMonth = null): ?string
    {
        return PayrollExchangeRate::where('currency', $currency)
            ->when($upToMonth, fn ($q) => $q->where('month', '<=', $upToMonth))
            ->orderByDesc('month')
            ->value('rate_to_aed');
    }

    /** شهر فيه كشف مدفوع أسعاره مقفلة — كي يطابق سعر الشهر دائماً سعر كل كشوفه */
    public function ratesLocked(string $month): bool
    {
        return EmployeeSalary::where('month', $month)->where('status', EmployeeSalary::STATUS_PAID)->exists();
    }

    private function storeRates(string $month, array $rates, ?User $by): void
    {
        $rates = array_intersect_key($rates, array_flip($this->foreignCurrencies()));

        if ($rates && $this->ratesLocked($month)) {
            $saved = PayrollExchangeRate::where('month', $month)->pluck('rate_to_aed', 'currency');

            // نفس السعر المحفوظ (كما يرسله نموذج التوليد) أو عملة بلا سعر بعد مسموحان — التغيير فقط ممنوع
            foreach ($rates as $currency => $rate) {
                if (isset($saved[$currency]) && round((float) $rate, 8) !== round((float) $saved[$currency], 8)) {
                    throw ValidationException::withMessages([
                        "rates.{$currency}" => "لا يمكن تعديل أسعار صرف شهر {$month} بعد دفع أحد كشوفه",
                    ]);
                }
            }
        }

        foreach ($rates as $currency => $rate) {
            PayrollExchangeRate::updateOrCreate(
                ['month' => $month, 'currency' => $currency],
                ['rate_to_aed' => $rate, 'updated_by' => $by?->id],
            );
        }
    }

    private function foreignCurrencies(): array
    {
        return array_values(array_diff(Employee::CURRENCIES, [Employee::BASE_CURRENCY]));
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
