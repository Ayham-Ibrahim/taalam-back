<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الكوبون المطبَّق على هذا الحجز (إن وُجد) + مقدار الخصم الفعلي — معلوماتي
 * فقط (للفاتورة/العرض)، لا يدخل في chk_bookings_amounts: amount_paid ما زال
 * يساوي teacher_amount + platform_amount تماماً، الخصم مقتطَع من platform_amount
 * مسبقاً قبل أي INSERT (راجع BookingService::createBookingRecord).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('package_id')->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0)->after('platform_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('discount_amount');
        });
    }
};
