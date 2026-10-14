<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * الداعم الأكاديمي — نوع معلم جديد (موظف شركة شريكة خارجية يدعم طلاباً جامعيين
 * بمهامهم الدراسية، لا جلسات/حجوزات تقليدية). MODIFY COLUMN خام بدل
 * Schema::table()->change() لأن doctrine/dbal غير مثبَّت في هذا المشروع
 * (نفس أسلوب 2026_01_01_000021_add_teacher_support_to_complaints_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE teachers MODIFY teacher_type ENUM('school', 'university', 'training_center', 'academic_support') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE teachers MODIFY teacher_type ENUM('school', 'university', 'training_center') NOT NULL");
    }
};
