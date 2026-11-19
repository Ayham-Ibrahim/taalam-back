<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إشعار الأدمن "بقيت جلسة واحدة في اشتراك الطالب" يُرسَل مرة واحدة لكل حجز — هذا العمود يمنع
 * تكراره (نظير subscription_ended_notified_at). عمود جديد فقط — لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('ending_soon_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('ending_soon_notified_at');
        });
    }
};
