<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدورات التدريبية فقط لها خياران: سعر إجمالي للدورة كاملة (total، الافتراضي
 * — يطابق كل الدورات الحالية بلا تغيير) أو سعر بالساعة يُضرب في total_hours
 * (hourly). الباقات ليس لها هذا الخيار — دائماً بالساعة (sessions_count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->enum('pricing_mode', ['total', 'hourly'])->default('total')->after('provider_price');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });
    }
};
