<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نسبة تعويض غياب الطلاب المثبَّتة على الجلسة لحظة انتهائها مع مبلغها (teacher_compensation_amount)
 * — للعرض على الأدمن فقط، فلا يغيّر تعديلُ الإعداد لاحقاً النسبةَ الظاهرة لجلسات سابقة. NULL =
 * لا تعويض، أو حُسم قبل التعويض النسبي (مبلغ ثابت).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->decimal('teacher_compensation_percent', 5, 2)->nullable()->after('teacher_compensation_amount');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('teacher_compensation_percent');
        });
    }
};
