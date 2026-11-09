<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * للمركز التدريبي صار قسم الخبرات = "الدورات المتاحة" (اسم الدورة + وصفها) — بلا فترة.
 * الفترة تبقى إلزامية للمدرس عبر الـ validation، لكن العمود يقبل null. لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_experiences', function (Blueprint $table) {
            $table->string('period', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_experiences', function (Blueprint $table) {
            $table->string('period', 100)->nullable(false)->change();
        });
    }
};
