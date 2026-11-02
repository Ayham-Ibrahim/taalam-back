<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * باقة تجربة مجانية (is_trial) — جلسة تعريفية بالمعلم، بسعر صفر وبلا مادة (لا بيانات مضللة).
 * chk_packages_price كان يفرض teacher_price > 0 على كل باقة؛ يبقى كذلك لكل باقة عادية، والاستثناء
 * الوحيد باقة تجربة بسعر صفر تماماً. subject_id يصبح اختيارياً لها (طلبات المعلم ما زالت تشترطه).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_trial')->default(false)->after('session_format');
            $table->foreignId('subject_id')->nullable()->change();
        });

        DB::statement('ALTER TABLE packages DROP CONSTRAINT chk_packages_price');
        DB::statement('
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_price
            CHECK (teacher_price > 0 OR (is_trial = 1 AND teacher_price = 0))
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE packages DROP CONSTRAINT chk_packages_price');
        DB::statement('
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_price
            CHECK (teacher_price > 0)
        ');

        Schema::table('packages', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable(false)->change();
            $table->dropColumn('is_trial');
        });
    }
};
