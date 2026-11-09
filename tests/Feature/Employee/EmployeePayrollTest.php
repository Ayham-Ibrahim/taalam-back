<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_can_create_and_list_employees_with_payroll_summary(): void
    {
        $accountant = User::factory()->create(['role' => 'accountant']);

        $this->actingAs($accountant)
            ->postJson('/api/employees', ['name' => 'سارة', 'job_title' => 'سكرتيرة', 'base_salary' => 3000])
            ->assertStatus(201)
            ->assertJsonPath('data.baseSalary', 3000);

        Employee::create(['name' => 'خالد', 'base_salary' => 2000, 'is_active' => false]);

        $this->actingAs($accountant)->getJson('/api/employees')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('additional_data.activeEmployees', 1)
            ->assertJsonPath('additional_data.monthlyBasePayroll', 3000);
    }

    public function test_non_finance_users_are_forbidden(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->getJson('/api/employees')->assertStatus(403);
        $this->actingAs($teacher)->postJson('/api/employees', ['name' => 'x', 'base_salary' => 1])->assertStatus(403);
        $this->actingAs($teacher)->getJson('/api/payroll/report')->assertStatus(403);
    }

    /** البونص قبل التوليد وبعده كلاهما يدخل الكشف، والخصم يُطرح */
    public function test_bonuses_and_deductions_flow_into_the_monthly_salary(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = Employee::create(['name' => 'سارة', 'base_salary' => 3000]);

        $this->actingAs($admin)
            ->postJson("/api/employees/{$employee->id}/adjustments", ['month' => '2026-10', 'amount' => 500, 'reason' => 'أداء ممتاز'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'bonus');

        $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => '2026-10'])
            ->assertStatus(201)
            ->assertJsonPath('data.created', 1);

        $this->actingAs($admin)
            ->postJson("/api/employees/{$employee->id}/adjustments", ['month' => '2026-10', 'type' => 'deduction', 'amount' => 200])
            ->assertStatus(201);

        $salary = EmployeeSalary::firstOrFail();
        $this->assertEquals(500.0, (float) $salary->bonuses_total);
        $this->assertEquals(200.0, (float) $salary->deductions_total);
        $this->assertEquals(3300.0, (float) $salary->net_amount);

        // التوليد مرة ثانية لا يكرر الكشف
        $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => '2026-10'])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 0);
        $this->assertDatabaseCount('employee_salaries', 1);

        $this->actingAs($admin)->getJson('/api/payroll?month=2026-10')
            ->assertStatus(200)
            ->assertJsonPath('data.0.netAmount', 3300)
            ->assertJsonPath('additional_data.outstandingTotal', 3300);
    }

    public function test_paid_month_is_locked(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = Employee::create(['name' => 'سارة', 'base_salary' => 3000]);

        $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => '2026-10']);
        $salary = EmployeeSalary::firstOrFail();

        $this->actingAs($admin)->postJson("/api/payroll/{$salary->id}/mark-paid")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'paid');

        $this->actingAs($admin)->postJson("/api/payroll/{$salary->id}/mark-paid")->assertStatus(422);
        $this->actingAs($admin)
            ->postJson("/api/employees/{$employee->id}/adjustments", ['month' => '2026-10', 'amount' => 100])
            ->assertStatus(422);

        // رفع الراتب الأساسي لا يغيّر كشفاً مدفوعاً، ولا يمكن حذف موظف له كشوف
        $this->actingAs($admin)->putJson("/api/employees/{$employee->id}", ['base_salary' => 4000])->assertStatus(200);
        $this->assertEquals(3000.0, (float) $salary->fresh()->net_amount);
        $this->actingAs($admin)->deleteJson("/api/employees/{$employee->id}")->assertStatus(422);
    }

    public function test_cannot_generate_payroll_for_a_month_that_has_not_started(): void
    {
        $admin = User::factory()->admin()->create();
        Employee::create(['name' => 'سارة', 'base_salary' => 3000]);

        $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => now()->addMonth()->format('Y-m')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');
        $this->assertDatabaseCount('employee_salaries', 0);

        $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => now()->format('Y-m')])
            ->assertStatus(201);
    }

    public function test_generation_skips_inactive_and_not_yet_hired_employees(): void
    {
        $admin = User::factory()->admin()->create();
        Employee::create(['name' => 'نشط', 'base_salary' => 1000]);
        Employee::create(['name' => 'معطل', 'base_salary' => 1000, 'is_active' => false]);
        Employee::create(['name' => 'لاحق', 'base_salary' => 1000, 'hired_at' => '2026-11-15']);

        $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => '2026-10'])
            ->assertJsonPath('data.created', 1);
    }

    public function test_comprehensive_report_aggregates_per_employee_and_month(): void
    {
        $admin = User::factory()->admin()->create();
        $sara = Employee::create(['name' => 'سارة', 'base_salary' => 3000]);
        Employee::create(['name' => 'خالد', 'base_salary' => 2000]);

        foreach (['2026-09', '2026-10'] as $month) {
            $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => $month]);
        }
        $this->actingAs($admin)->postJson("/api/employees/{$sara->id}/adjustments", ['month' => '2026-10', 'amount' => 500]);
        $this->actingAs($admin)->postJson('/api/payroll/'.EmployeeSalary::where('month', '2026-09')->where('employee_id', $sara->id)->value('id').'/mark-paid');

        $response = $this->actingAs($admin)->getJson('/api/payroll/report?from=2026-09&to=2026-10')->assertStatus(200);

        $response->assertJsonPath('data.summary.activeEmployees', 2)
            ->assertJsonPath('data.summary.bonusesTotal', 500)
            ->assertJsonPath('data.summary.netTotal', 10500)
            ->assertJsonPath('data.summary.paidAmount', 3000)
            ->assertJsonPath('data.summary.outstandingAmount', 7500)
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonCount(2, 'data.months');

        $saraRow = collect($response->json('data.rows'))->firstWhere('employeeId', $sara->id);
        $this->assertSame(2, $saraRow['monthsCount']);
        $this->assertEquals(6500, $saraRow['netTotal']);
        $this->assertEquals(3000, $saraRow['paidAmount']);
        $this->assertEquals(3500, $saraRow['outstandingAmount']);
    }

    public function test_payroll_export_downloads_excel(): void
    {
        $admin = User::factory()->admin()->create();
        Employee::create(['name' => 'سارة', 'base_salary' => 3000]);
        $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => '2026-10']);

        $this->actingAs($admin)->get('/api/payroll/export?month=2026-10')
            ->assertStatus(200)
            ->assertDownload('employee-payroll.xlsx');
    }

    public function test_report_export_downloads_a_multi_sheet_workbook(): void
    {
        $admin = User::factory()->admin()->create();
        Employee::create(['name' => 'سارة', 'base_salary' => 3000]);
        $this->actingAs($admin)->postJson('/api/payroll/generate', ['month' => now()->format('Y-m')]);

        $this->actingAs($admin)->get('/api/payroll/report/export')
            ->assertStatus(200)
            ->assertDownload('payroll-report.xlsx');
    }
}
