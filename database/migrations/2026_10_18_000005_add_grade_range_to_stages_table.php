<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نطاق الصفوف لكل مرحلة مدرسية (مثلاً الإعدادية 7–9) — يستخدمه فلتر البحث كي
 * تعرض قائمة "الصف" صفوف المرحلة المختارة فقط. nullable لأنه لا معنى له
 * للمراحل الجامعية/التدريبية. القيم الأولية تُستنتج من اسم المرحلة الحالي
 * ويستطيع الأدمن تعديلها من صفحة التصنيفات.
 */
return new class extends Migration
{
    private const DEFAULT_RANGES = [
        'ابتدائ' => [1, 6],
        'اعدادي' => [7, 9],
        'إعدادي' => [7, 9],
        'متوسط' => [7, 9],
        'ثانوي' => [10, 12],
    ];

    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->unsignedTinyInteger('grade_from')->nullable()->after('education_type');
            $table->unsignedTinyInteger('grade_to')->nullable()->after('grade_from');
        });

        foreach (self::DEFAULT_RANGES as $needle => [$from, $to]) {
            DB::table('stages')
                ->where('education_type', 'school')
                ->whereNull('grade_from')
                ->where('name_ar', 'like', "%{$needle}%")
                ->update(['grade_from' => $from, 'grade_to' => $to]);
        }
    }

    public function down(): void
    {
        Schema::table('stages', function (Blueprint $table) {
            $table->dropColumn(['grade_from', 'grade_to']);
        });
    }
};
