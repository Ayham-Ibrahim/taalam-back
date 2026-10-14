<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * يسمح بطلب تغيير موعد بلا موعد مقترح — حالة "تبليغ غياب طارئ" (المعلم/الطالب
 * يُخبر الإدارة أنه لن يتمكن من حضور الجلسة دون اقتراح بديل فوري، تاركاً
 * تنسيق الموعد الجديد للأدمن بعد التواصل المباشر). السبب يصبح إلزامياً عندها
 * بدلاً من الموعد المقترح — انظر RescheduleService::request.
 * MODIFY COLUMN خام بدل Schema::table()->change() لأن doctrine/dbal غير
 * مثبَّت في هذا المشروع (نفس أسلوب 2026_01_01_000021_add_teacher_support_to_complaints_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE reschedule_requests MODIFY proposed_scheduled_at DATETIME NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reschedule_requests MODIFY proposed_scheduled_at DATETIME NOT NULL');
    }
};
