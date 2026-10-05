<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الكوبون كان نسبة خصم فقط (discount_percent) — يضيف خيار "مبلغ ثابت" أيضاً.
 * discount_value يحمل الرقمين معاً (نسبة أو مبلغ) حسب discount_type، بدل عمودين
 * منفصلين يبقى أحدهما فارغاً دائماً. السقف الآمن نفسه يبقى قائماً في
 * CreateCouponRequest/UpdateCouponRequest (الخصم لا يتجاوز هامش المنصة على
 * الباقة — نسبةً أو مبلغاً حسب النوع)، فمستحق المعلم يبقى غير متأثر دائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE coupons CHANGE discount_percent discount_value DECIMAL(10,2) NOT NULL');

        Schema::table('coupons', function (Blueprint $table) {
            $table->enum('discount_type', ['percent', 'fixed'])->default('percent')->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('discount_type');
        });

        DB::statement('ALTER TABLE coupons CHANGE discount_value discount_percent DECIMAL(5,2) NOT NULL');
    }
};
