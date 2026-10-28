<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نفس نمط booking_id/enrollment_id بالضبط — nullable جنباً إلى جنب، بلا قيد
 * XOR على مستوى القاعدة (PaymentService::handleCheckoutCompleted يملك فعلاً
 * فرع else يتوقّع دفعة غير مرتبطة بأي منهما، فهذا امتداد للنمط القائم لا تصميم جديد).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('support_package_purchase_id')->nullable()
                ->after('enrollment_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('support_package_purchase_id');
        });
    }
};
