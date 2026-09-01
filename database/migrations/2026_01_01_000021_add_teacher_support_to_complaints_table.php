<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الشكاوى كانت مقصورة على الطلاب فقط (student_id إلزامي). الآن يمكن للمعلم
 * أيضاً تقديم شكوى (مثلاً عبر نموذج "تواصل معنا" العام) — student_id يصبح
 * اختيارياً ويُضاف teacher_id اختياري، مع قيد CHECK يضمن وجود أحدهما دائماً
 * (نفس أسلوب قيود add_check_constraints.php). MODIFY COLUMN خام بدل
 * Schema::table()->change() لأن doctrine/dbal غير مثبَّت في هذا المشروع.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE complaints MODIFY student_id BIGINT UNSIGNED NULL');

        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('student_id')
                ->constrained()->restrictOnDelete();
        });

        DB::statement('
            ALTER TABLE complaints
            ADD CONSTRAINT chk_complaints_filer
            CHECK (student_id IS NOT NULL OR teacher_id IS NOT NULL)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE complaints DROP CONSTRAINT chk_complaints_filer');

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_id');
        });

        DB::statement('ALTER TABLE complaints MODIFY student_id BIGINT UNSIGNED NOT NULL');
    }
};
