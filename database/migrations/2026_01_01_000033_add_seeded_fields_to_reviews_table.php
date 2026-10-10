<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يسمح للأدمن بإدخال تقييمات/مراجعات يدوياً (رفع ملف Excel) لمعلم معيّن —
 * بيانات تاريخية/مؤقتة لا طالب حقيقي وراءها. student_id كانت NOT NULL
 * إجبارياً، فنجعلها nullable هنا، ونضيف reviewer_name كاسم بديل يُعرض بدل
 * اسم الطالب الحقيقي حين لا يوجد. is_seeded يميّز هذه الصفوف عن تقييمات
 * الطلاب الفعلية (تحكّم الحذف مثلاً يُقصر عليها فقط)، وimported_by للتتبّع.
 * القيد الفريد (student_id, class_session_id) يبقى سليماً: MySQL يعامل كل
 * NULL كقيمة مستقلة في unique index، فلا يمنع إدخال عدة صفوف student_id=NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE reviews MODIFY student_id BIGINT UNSIGNED NULL');

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('reviewer_name')->nullable()->after('student_id');
            $table->boolean('is_seeded')->default(false)->index()->after('report_reason');
            $table->foreignId('imported_by')->nullable()->after('is_seeded')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imported_by');
            $table->dropColumn(['reviewer_name', 'is_seeded']);
        });

        DB::statement('ALTER TABLE reviews MODIFY student_id BIGINT UNSIGNED NOT NULL');
    }
};
