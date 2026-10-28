<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نتيجة الحضور التلقائي (من session_join_logs) تُحسم مرة واحدة عند انتهاء الجلسة
 * (SessionService::completeEndedSessions) مع نسبة مستحق المعلم منها لحظتها — كي لا
 * يغيّر تعديلُ الأدمن للنسبة لاحقاً حسابَ جلسات سابقة. NULL = جلسة لم تُقيَّم (قبل
 * هذا التعديل) فتُحتسب كاملة كما كانت. داخلي للأدمن/المستحقات فقط (ClassSession::$hidden).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->string('attendance_outcome', 30)->nullable()->after('status');
            $table->decimal('teacher_payout_percent', 5, 2)->nullable()->after('attendance_outcome');
        });

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

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn(['attendance_outcome', 'teacher_payout_percent']);
        });

        DB::table('settings')->where('key', 'student_no_show_teacher_payout_percent')->delete();
    }
};
