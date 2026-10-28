<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كوبون الأدمن متعدد الباقات يشمل دورات المراكز التدريبية أيضاً — coupon_course
 * يوازي coupon_package تماماً. enrollments تأخذ coupon_id + discount_amount بنفس
 * منطق bookings (add_coupon_to_bookings_table): الخصم يُقتطع من platform_amount
 * فقط قبل الـ INSERT، فيبقى amount_paid = provider_amount + platform_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['coupon_id', 'course_id']);
            $table->index('course_id');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('course_id')->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0)->after('platform_amount');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('discount_amount');
        });

        Schema::dropIfExists('coupon_course');
    }
};
