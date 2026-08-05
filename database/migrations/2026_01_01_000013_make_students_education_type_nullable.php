<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * حساب طالب يُنشئه الأدمن مباشرة (إيميل/باسوورد بلا بيانات أكاديمية) يبقى
 * education_type فيه NULL حتى يكمل الطالب ملفه بنفسه — وهذا هو إشارة اكتمال
 * الملف الشخصي نفسها (لا حاجة لعمود منفصل). raw SQL لأن doctrine/dbal غير مُثبَّت.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE students MODIFY COLUMN education_type ENUM('school','university','training') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE students MODIFY COLUMN education_type ENUM('school','university','training') NOT NULL");
    }
};
