<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** يوازي bookings.coupon_id/discount_amount تماماً — إعلامي فقط، الخصم مُطبَّق مسبقاً على amount وقت initiate(). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_package_purchases', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('support_package_id')->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('support_package_purchases', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn(['coupon_id', 'discount_amount']);
        });
    }
};
