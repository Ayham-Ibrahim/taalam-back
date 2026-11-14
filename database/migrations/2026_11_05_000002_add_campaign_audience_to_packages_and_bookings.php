<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * باقات الحملة المخفضة — ينشئها الأدمن باسم معلم، تظهر لحسابات الحملة فقط، ومبلغها كله
 * للمنصة (المعلم يأخذ راتباً شهرياً ثابتاً عنها لا مستحقاً لكل جلسة).
 *
 * audience: public لكل الباقات الحالية (الافتراضي) — لا يتغير شيء في الموجود.
 * bookings.excluded_from_payouts: نسخة وقت الحجز من كون الباقة للحملة — يُستبعد بها
 * الحجز من المستحقات وتعويض غياب الطالب.
 *
 * chk_packages_price يُوسَّع فقط (كل صف قائم يحقق الشرط الأضيق أصلاً): باقة الحملة سعر
 * معلمها صفر — لا مستحق له عنها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->enum('audience', ['public', 'campaign'])->default('public')->after('is_trial')->index();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('excluded_from_payouts')->default(false)->after('is_manual');
        });

        DB::statement('ALTER TABLE packages DROP CONSTRAINT chk_packages_price');
        DB::statement("
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_price
            CHECK (
                teacher_price > 0
                OR (is_trial = 1 AND teacher_price = 0)
                OR (audience = 'campaign' AND teacher_price = 0)
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE packages DROP CONSTRAINT chk_packages_price');
        DB::statement('
            ALTER TABLE packages
            ADD CONSTRAINT chk_packages_price
            CHECK (teacher_price > 0 OR (is_trial = 1 AND teacher_price = 0))
        ');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('excluded_from_payouts');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['audience']);
            $table->dropColumn('audience');
        });
    }
};
