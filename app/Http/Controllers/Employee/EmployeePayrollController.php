<?php

namespace App\Http\Controllers\Employee;

use App\Exports\EmployeePayrollExport;
use App\Exports\PayrollReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\GeneratePayrollRequest;
use App\Http\Requests\Employee\MarkSalaryPaidRequest;
use App\Http\Requests\Employee\UpdatePayrollRatesRequest;
use App\Http\Resources\Employee\EmployeeSalaryResource;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Services\EmployeePayrollService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class EmployeePayrollController extends Controller
{
    public function __construct(private readonly EmployeePayrollService $payrollService) {}

    /** كشف الرواتب — عادةً لشهر واحد (month)، مع مجاميعه بالدرهم في additional_data */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate($this->filterRules());

        $query = $this->filteredQuery($request);

        $summary = (clone $query)->toBase()
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(base_salary_aed), 0) AS base_total, COALESCE(SUM(bonuses_total_aed), 0) AS bonuses_total')
            ->selectRaw('COALESCE(SUM(deductions_total_aed), 0) AS deductions_total, COALESCE(SUM(net_amount_aed), 0) AS net_total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount_aed END), 0) AS paid_total")
            ->first();

        $salaries = $query->with(['employee:id,name,job_title', 'payer:id,name'])
            ->orderBy('month', 'desc')
            ->orderBy('employee_id')
            ->paginate($request->integer('per_page', 20));

        return $this->paginateWithData(
            $salaries->through(fn (EmployeeSalary $s) => new EmployeeSalaryResource($s)),
            [
                'currency' => Employee::BASE_CURRENCY,
                'count' => (int) $summary->count,
                'baseTotal' => round((float) $summary->base_total, 2),
                'bonusesTotal' => round((float) $summary->bonuses_total, 2),
                'deductionsTotal' => round((float) $summary->deductions_total, 2),
                'netTotal' => round((float) $summary->net_total, 2),
                'paidTotal' => round((float) $summary->paid_total, 2),
                'outstandingTotal' => round((float) $summary->net_total - (float) $summary->paid_total, 2),
            ],
        );
    }

    public function generate(GeneratePayrollRequest $request)
    {
        $month = $request->validated('month');
        $created = $this->payrollService->generateMonth($month, array_filter($request->validated('rates') ?? []), $request->user());

        return $this->success(
            ['month' => $month, 'created' => $created],
            $created > 0 ? "تم توليد {$created} كشف راتب لشهر {$month}" : "كشوف شهر {$month} مولَّدة مسبقاً وتم تحديثها",
            $created > 0 ? 201 : 200,
        );
    }

    /** أسعار صرف شهر: المحفوظة له، أو آخر سعر محفوظ كاقتراح (saved=false) */
    public function rates(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = $request->input('month', now()->format('Y-m'));

        return $this->success($this->ratesPayload($month));
    }

    /** حفظ أسعار شهر — يعيد حساب كشوفه غير المدفوعة، ومرفوض بعد دفع أي كشف فيه */
    public function updateRates(UpdatePayrollRatesRequest $request)
    {
        $month = $request->validated('month');

        $this->payrollService->updateRates($month, array_filter($request->validated('rates')), $request->user());

        return $this->success($this->ratesPayload($month), 'تم حفظ أسعار الصرف');
    }

    /** locked: دُفع كشف في الشهر فلا تُعدَّل أسعاره */
    private function ratesPayload(string $month): array
    {
        return [
            'month' => $month,
            'locked' => $this->payrollService->ratesLocked($month),
            'rates' => $this->payrollService->ratesFor($month),
        ];
    }

    public function markPaid(MarkSalaryPaidRequest $request, EmployeeSalary $salary)
    {
        $salary = $this->payrollService->markPaid($salary, $request->user(), $request->validated('notes'));

        return $this->success(new EmployeeSalaryResource($salary->load(['employee:id,name,job_title', 'payer:id,name'])), 'تم تسجيل دفع الراتب');
    }

    /** التقرير الشامل: مجاميع لكل موظف + تفصيل شهري + مجاميع عامة — راجع EmployeePayrollService::report */
    public function report(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        return $this->success($this->payrollService->report(
            $request->input('from'),
            $request->input('to'),
            $request->integer('employee_id') ?: null,
        ));
    }

    /** التقرير الشامل كملف Excel بثلاث أوراق: حسب الموظف، حسب الشهر، التفاصيل — نفس فلاتر report */
    public function exportReport(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        return Excel::download(
            new PayrollReportExport($this->payrollService, $request->only(['from', 'to', 'employee_id'])),
            'payroll-report.xlsx',
        );
    }

    public function export(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate($this->filterRules());

        return Excel::download(
            new EmployeePayrollExport($request->only(['month', 'from', 'to', 'status', 'employee_id'])),
            'employee-payroll.xlsx',
        );
    }

    private function filterRules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,paid'],
            'employee_id' => ['nullable', 'integer'],
        ];
    }

    private function filteredQuery(Request $request)
    {
        return EmployeeSalary::filter($request->only(['month', 'from', 'to', 'status', 'employee_id']));
    }
}
