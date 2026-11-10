<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رواتب بعملات متعددة (AED/USD/EGP) مع مقابلها بالدرهم. payroll_exchange_rates: سعر كل عملة
 * مقابل الدرهم لشهر (1 وحدة = rate_to_aed درهم). employee_salaries يحفظ لقطة السعر والمبالغ
 * بالدرهم — تُعاد ما دام الكشف pending وتُقفل بعد الدفع، فتغيّر السعر لاحقاً لا يمس الماضي.
 * الكشوف الموجودة كلها بالدرهم فتُعبأ بسعر 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7); // YYYY-MM
            $table->char('currency', 3);
            $table->decimal('rate_to_aed', 16, 8);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['month', 'currency']);
        });

        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->decimal('exchange_rate', 16, 8)->default(1)->after('currency');
            $table->decimal('base_salary_aed', 12, 2)->default(0)->after('exchange_rate');
            $table->decimal('bonuses_total_aed', 12, 2)->default(0)->after('base_salary_aed');
            $table->decimal('deductions_total_aed', 12, 2)->default(0)->after('bonuses_total_aed');
            $table->decimal('net_amount_aed', 12, 2)->default(0)->after('deductions_total_aed');
        });

        DB::table('employee_salaries')->where('currency', 'AED')->update([
            'exchange_rate' => 1,
            'base_salary_aed' => DB::raw('base_salary'),
            'bonuses_total_aed' => DB::raw('bonuses_total'),
            'deductions_total_aed' => DB::raw('deductions_total'),
            'net_amount_aed' => DB::raw('net_amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'base_salary_aed', 'bonuses_total_aed', 'deductions_total_aed', 'net_amount_aed']);
        });

        Schema::dropIfExists('payroll_exchange_rates');
    }
};
