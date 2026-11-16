<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلب موعد جلسة تعويضية يمرّ بنفس مسار طلب تغيير الموعد (رد الطرف الآخر، المهلة، التصعيد
 * للأدمن) — type يميّز بينهما. makeup: الجلسة المرتبطة هي الجلسة الفائتة (غاب عنها المعلم)،
 * والموافقة تُنشئ جلسة تعويضية بالموعد المقترح بدل نقل الجلسة نفسها. كل الطلبات القائمة reschedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reschedule_requests', function (Blueprint $table) {
            $table->enum('type', ['reschedule', 'makeup'])->default('reschedule')->after('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('reschedule_requests', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
