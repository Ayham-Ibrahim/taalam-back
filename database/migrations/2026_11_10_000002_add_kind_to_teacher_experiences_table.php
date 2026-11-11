<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المدرس الجامعي صار له قسمان بنفس الجدول: "الخبرات السابقة" (experience) و"الدورات المتاحة"
 * (offered_course: اسم الدورة + وصفها، بلا فترة). كل الصفوف الحالية تبقى experience
 * (القيمة الافتراضية) — عمود جديد فقط، لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_experiences', function (Blueprint $table) {
            $table->enum('kind', ['experience', 'offered_course'])->default('experience')->after('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_experiences', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
