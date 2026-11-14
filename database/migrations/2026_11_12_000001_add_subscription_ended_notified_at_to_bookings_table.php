<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بريد "انتهى اشتراكك بالباقة — جدّد مع نفس المعلم" يُرسَل مرة واحدة لكل حجز عند انتهاء آخر
 * جلساته — هذا العمود يمنع تكراره. عمود جديد فقط — لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('subscription_ended_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('subscription_ended_notified_at');
        });
    }
};
