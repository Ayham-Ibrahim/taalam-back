<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كوبون خصم على باقة محددة — ينشئه المعلم (أو الأدمن) ويُبلَّغ الطالب بالكود
 * خارج المنصة (اتصال/رسالة)، ثم يُدخله عند الحجز. الخصم يُقتطع من حصة المنصة
 * فقط (platform_amount) ولا يمس مستحق المعلم إطلاقاً — نفس منطق الهامش
 * (platform_margin_percent) تماماً: discount_percent هنا لا يمكن أن يتجاوز
 * هامش المنصة على الباقة وقت إنشاء الكوبون (يُفرض في CreateCouponRequest)،
 * فيبقى amount_paid = teacher_amount + platform_amount صحيحاً دائماً
 * (chk_bookings_amounts) بلا الحاجة لأي استثناء عليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();

            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            // منسوخ من package.teacher_id وقت الإنشاء — يسمح بتوسيع صلاحية المعلم على
            // كوبوناته مباشرة دون الحاجة لـ join عبر packages في كل تحقق صلاحية.
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->decimal('discount_percent', 5, 2);

            $table->unsignedInteger('max_redemptions')->nullable(); // null = بلا حد
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['package_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
