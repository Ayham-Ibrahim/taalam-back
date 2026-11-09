<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الموظفون ورواتبهم الشهرية — كيان مستقل بلا حساب مستخدم (ليسوا معلمين ولا حسابات دخول).
 * employee_adjustments: بونص/خصم لموظف في شهر. employee_salaries: كشف الشهر لكل موظف،
 * يُعاد حسابه ما دام غير مدفوع ويُقفل بعد الدفع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('job_title', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->decimal('base_salary', 12, 2);
            $table->char('currency', 3)->default('AED');
            $table->date('hired_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->enum('type', ['bonus', 'deduction']);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'month']);
        });

        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->char('month', 7)->index(); // YYYY-MM
            $table->decimal('base_salary', 12, 2);
            $table->decimal('bonuses_total', 12, 2)->default(0);
            $table->decimal('deductions_total', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->char('currency', 3)->default('AED');
            $table->enum('status', ['pending', 'paid'])->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salaries');
        Schema::dropIfExists('employee_adjustments');
        Schema::dropIfExists('employees');
    }
};
