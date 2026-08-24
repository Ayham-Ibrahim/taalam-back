<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لا مكان لهذين الحقلين في المخطط أصلاً قبل هذا — أُضيفا خصيصاً لدعم استيراد
 * الطلاب من ملفات Excel خارجية (مثال: قوائم شركاء) تحمل مدينة/بلد الطالب
 * كبيانات إضافية غير إلزامية (راجع StudentImportService::validateRow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('birth_date');
            $table->string('country', 100)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['city', 'country']);
        });
    }
};
