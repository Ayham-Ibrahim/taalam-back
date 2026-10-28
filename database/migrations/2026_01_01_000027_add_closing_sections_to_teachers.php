<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول قسمَي "فلسفتي في التدريس" و"شكراً لزيارتك" في نهاية بروفايل المعلم
 * العام — نصوص حرة يكتبها المعلم من لوحة التحكم؛ إن تُركت فارغة يُخفى قسم
 * الفلسفة بالكامل بينما يظهر قسم الشكر بنص افتراضي من الواجهة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('teaching_philosophy_quote', 200)->nullable()->after('bio');
            $table->text('teaching_philosophy_text')->nullable()->after('teaching_philosophy_quote');
            $table->text('thank_you_message')->nullable()->after('teaching_philosophy_text');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['teaching_philosophy_quote', 'teaching_philosophy_text', 'thank_you_message']);
        });
    }
};
