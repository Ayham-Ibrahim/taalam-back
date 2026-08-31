<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الصفوف المستهدَفة (1-12) — أرقام صِرفة بلا جدول تصنيف منفصل، تماماً مثل
 * students.grade، لا مثل stages (تصنيف بأسماء يديره الأدمن). قائمة JSON بدل
 * جدول pivot لأن القيم أرقام ذاتية الوضوح بلا حاجة لاسم/ترتيب/إدارة، ويوازي
 * نمط import_batches.errors الموجود مسبقاً في هذا المشروع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->json('grades')->nullable()->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('grades');
        });
    }
};
