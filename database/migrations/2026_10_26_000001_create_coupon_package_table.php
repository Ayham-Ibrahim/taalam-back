<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كوبون أدمن متعدد الباقات — كوبون عادي في جدول coupons لكن package_id و
 * support_package_id كلاهما null، والباقات المشمولة تُربط هنا. لا يمس الكوبونات
 * الحالية (باقة واحدة / باقة دعم أكاديمي) إطلاقاً. نفس سقف الخصم يبقى قائماً:
 * الخصم لا يتجاوز هامش المنصة على أي باقة مشمولة (يُفرض في
 * CreateMultiPackageCouponRequest/UpdateMultiPackageCouponRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_package', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['coupon_id', 'package_id']);
            $table->index('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_package');
    }
};
