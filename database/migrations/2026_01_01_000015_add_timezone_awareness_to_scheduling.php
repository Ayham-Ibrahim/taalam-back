<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.timezone/teachers.timezone كانا موجودين مسبقاً لكن غير مستخدَمين إطلاقاً —
 * كل وقت يدخله مستخدم كان يُخزَّن حرفياً وكأنه UTC بلا أي تحويل فعلي (نفس رقم
 * الساعة يُعاد بلا تعديل)، فيظهر مُزاحاً لدى أي طرف آخر في منطقة زمنية مختلفة.
 *
 * timezone_auto: هل نُحدِّث users.timezone تلقائياً من متصفح المستخدم في كل جلسة،
 * أم ثبَّته المستخدم يدوياً من الإعدادات (عندها لا يُكتَب فوقه تلقائياً بعد الآن).
 *
 * requested_timezone / schedule_timezone: المنطقة الزمنية التي كانت سارية لحظة
 * إدخال الوقت الخام (طلب حجز فردي، أو جدول باقة/دورة متكرر) — ضرورية لاحقاً عند
 * تحويل ذلك الوقت الخام إلى لحظة UTC صحيحة، إذ لا تُخزَّن اللحظة نفسها فوراً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('timezone_auto')->default(true)->after('timezone');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('requested_timezone', 64)->nullable()->after('requested_start_time');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->string('schedule_timezone', 64)->nullable()->after('teacher_id');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('schedule_timezone', 64)->nullable()->after('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone_auto');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('requested_timezone');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('schedule_timezone');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('schedule_timezone');
        });
    }
};
