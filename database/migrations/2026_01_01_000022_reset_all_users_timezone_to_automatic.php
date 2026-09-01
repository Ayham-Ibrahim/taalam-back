<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * حقل تثبيت المنطقة الزمنية يدوياً أُخفي من واجهة الإعدادات (الطالب والمعلم) —
 * تبقى المنطقة الزمنية تلقائية دوماً (اكتشاف صامت عبر useSyncTimezone). أي
 * مستخدم كان قد عطّل "التلقائي" مسبقاً (timezone_auto=false) سيبقى عالقاً على
 * قيمته المحفوظة للأبد بلا أي طريقة لإرجاعه، بما أن الحقل الوحيد لتغييره
 * أُخفي — لذا نعيد ضبط الجميع مرة واحدة هنا. تعديل بيانات فقط، لا تغيير مخطط.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('timezone_auto', false)->update(['timezone_auto' => true]);
    }

    public function down(): void
    {
        // لا يمكن استرجاع القيم اليدوية السابقة (لم تُحفَظ) — هذا الإصلاح غير قابل للتراجع عمداً.
    }
};
