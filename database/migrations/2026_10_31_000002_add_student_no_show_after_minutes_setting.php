<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مهلة دخول المعلم والطالب في الحضور التلقائي (SessionService::attendanceOutcome): لم يدخل المعلم خلال
 * أول هذه الدقائق → غياب المعلم (لا مستحق). دخل المعلم ولم يدخل أي
 * طالب خلال أول هذه الدقائق من الجلسة → غياب الطلاب (student_no_show)، ولو دخل طالب بعدها.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'student_no_show_after_minutes',
            'value' => '20',
            'type' => 'integer',
            'group' => 'attendance',
            'label_ar' => 'مهلة دخول المعلم والطالب قبل اعتباره غائباً (دقيقة)',
            'description' => 'لم يدخل المعلم خلال هذه الدقائق من بداية الجلسة — غياب المعلم ولا مستحق له، ولو دخل بعدها. دخل المعلم في المهلة ولم يدخل أي طالب خلالها — غياب الطلاب ويُصرف للمعلم التعويض الثابت، ولو دخل طالب بعدها',
            'is_editable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'student_no_show_after_minutes')->delete();
    }
};
