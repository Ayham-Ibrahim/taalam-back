<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أوقات توفّر أسبوعية معروضة للطالب في البروفايل العام ("أوقات التوفر هذا
 * الأسبوع") + تلميح للحجز — نافذة أو أكثر لكل يوم. منفصلة تماماً عن جدول
 * availability_slots الذي يبقى كما هو (أيام فقط، بلا أوقات) لأنه يغذّي منتقي
 * أيام الباقة الفردية. هذا الجدول للعرض/التلميح فقط ولا يدخل في أي حساب حجز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_availability_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = الأحد ... 6 = السبت
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['teacher_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_availability_times');
    }
};
