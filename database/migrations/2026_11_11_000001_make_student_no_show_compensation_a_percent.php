<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تعويض المعلم عند غياب الطلاب صار نسبة من قيمة الجلسة (PayoutService::sessionValues) بدل
 * مبلغ ثابت. المبلغ الناتج يُثبَّت على الجلسة لحظة انتهائها (teacher_compensation_amount) كما
 * كان، فلا تتغير جلسات سابقة ولا باقي حساب المستحقات.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'student_no_show_teacher_compensation_percent',
            'value' => '0',
            'type' => 'decimal',
            'group' => 'attendance',
            'label_ar' => 'نسبة تعويض المعلم عند غياب الطلاب (%)',
            'description' => 'دخل المعلم الجلسة ولم يدخلها أي طالب — يُصرف للمعلم هذه النسبة من قيمة الجلسة مرة واحدة بدل مستحقها، وتُخصم من عائد المنصة',
            'is_editable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('settings')->where('key', 'student_no_show_teacher_compensation_amount')->delete();
    }

    public function down(): void
    {
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

        DB::table('settings')->where('key', 'student_no_show_teacher_compensation_percent')->delete();
    }
};
