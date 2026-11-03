<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بيانات متصفح الطالب لحظة ضغطه "ادفع" (IP، المتصفح، كوكيز _fbp/_fbc، رابط الصفحة) —
 * حدث Purchase يُرسَل لاحقاً من webhook Stripe الذي لا يحمل أياً منها، فتُحفظ هنا مسبقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->json('meta_context')->nullable()->after('gateway_payload');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('meta_context');
        });
    }
};
