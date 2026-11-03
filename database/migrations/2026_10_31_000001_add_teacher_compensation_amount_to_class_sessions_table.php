<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تعويض المعلم عند غياب الطلاب (student_no_show) صار مبلغاً ثابتاً يحدده الأدمن بدل نسبة من
 * مستحق الجلسة — مرة واحدة للجلسة أياً كان عدد طلابها، ويتحمّله هامش المنصة. يُثبَّت على الجلسة
 * لحظة انتهائها (SessionService::completeEndedSessions) كالنسبة، فلا يغيّر تعديلُ الإعداد جلسات
 * سابقة. جلسات غياب الطلاب المحسومة قبل هذا تبقى على نسبتها المثبَّتة (teacher_payout_percent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->decimal('teacher_compensation_amount', 10, 2)->nullable()->after('teacher_payout_percent');
        });

        DB::table('settings')->insertOrIgnore([
            'key' => 'student_no_show_teacher_compensation_amount',
            'value' => '0',
            'type' => 'decimal',
            'group' => 'attendance',
            'label_ar' => 'تعويض المعلم عند غياب الطلاب (مبلغ ثابت)',
            'description' => 'دخل المعلم الجلسة ولم يدخلها أي طالب — يُصرف للمعلم هذا المبلغ مرة واحدة عن الجلسة بدل مستحقها، ويُخصم من عائد المنصة',
            'is_editable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('settings')->where('key', 'student_no_show_teacher_payout_percent')->delete();
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('teacher_compensation_amount');
        });

        DB::table('settings')->where('key', 'student_no_show_teacher_compensation_amount')->delete();

        DB::table('settings')->insertOrIgnore([
            'key' => 'student_no_show_teacher_payout_percent',
            'value' => '50',
            'type' => 'decimal',
            'group' => 'attendance',
            'label_ar' => 'نسبة تعويض المعلم عند غياب الطالب (%)',
            'description' => 'دخل المعلم الجلسة ولم يدخلها أي طالب ولم يُبلغ عن غيابه مسبقاً — يُصرف للمعلم هذه النسبة من مستحق الجلسة',
            'is_editable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
