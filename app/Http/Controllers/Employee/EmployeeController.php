<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeAdjustmentRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\Employee\EmployeeAdjustmentResource;
use App\Http\Resources\Employee\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeAdjustment;
use App\Services\EmployeePayrollService;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeePayrollService $payrollService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $employees = Employee::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('job_title', 'like', $term));
            })
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        $summary = [
            'activeEmployees' => Employee::where('is_active', true)->count(),
            'monthlyBasePayroll' => $this->payrollService->currentMonthlyBasePayroll(), // بالدرهم
        ];

        return $this->paginateWithData($employees->through(fn (Employee $e) => new EmployeeResource($e)), $summary);
    }

    /** الموظف مع سجل كشوفه وبونصاته/خصوماته — الأحدث أولاً */
    public function show(Employee $employee)
    {
        $this->authorize('viewAny', Employee::class);

        $employee->load([
            'salaries' => fn ($q) => $q->with('payer:id,name')->orderByDesc('month'),
            'adjustments' => fn ($q) => $q->with('creator:id,name')->orderByDesc('month')->latest(),
        ]);

        return $this->success(new EmployeeResource($employee));
    }

    public function store(StoreEmployeeRequest $request)
    {
        $employee = $this->payrollService->create($request->validated());

        return $this->success(new EmployeeResource($employee), 'تمت إضافة الموظف بنجاح', 201);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee)
    {
        $employee = $this->payrollService->update($employee, $request->validated());

        return $this->success(new EmployeeResource($employee), 'تم تعديل بيانات الموظف');
    }

    public function destroy(Employee $employee)
    {
        $this->authorize('manage', Employee::class);

        $this->payrollService->delete($employee);

        return $this->success(null, 'تم حذف الموظف');
    }

    /** إضافة بونص (أو خصم) لشهر — ينعكس فوراً على كشف ذلك الشهر إن كان مولَّداً وغير مدفوع */
    public function storeAdjustment(StoreEmployeeAdjustmentRequest $request, Employee $employee)
    {
        $adjustment = $this->payrollService->addAdjustment($employee, $request->validated(), $request->user());

        $message = $adjustment->type === EmployeeAdjustment::TYPE_BONUS ? 'تمت إضافة البونص' : 'تمت إضافة الخصم';

        return $this->success(new EmployeeAdjustmentResource($adjustment->load('creator:id,name')), $message, 201);
    }

    public function destroyAdjustment(EmployeeAdjustment $adjustment)
    {
        $this->authorize('manage', Employee::class);

        $this->payrollService->deleteAdjustment($adjustment);

        return $this->success(null, 'تم الحذف');
    }
}
