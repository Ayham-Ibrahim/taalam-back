<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صورة مصغّرة يرفعها الداعم يدوياً مع الملف (اختيارية) — بلا توليد تلقائي من
 * محتوى الملف (PPTX/XLSX لا يمكن تصيير معاينة حقيقية منها بدون محرّك تحويل
 * خارجي على السيرفر، وهذا تأجيل متعمَّد). بلا صورة مرفوعة، تعرض الواجهة أيقونة
 * نوع الملف الافتراضية بدلاً منها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_supporter_gallery_items', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('academic_supporter_gallery_items', function (Blueprint $table) {
            $table->dropColumn('thumbnail_path');
        });
    }
};
