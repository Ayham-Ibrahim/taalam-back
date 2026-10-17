<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * يضيف دور "academic_support" إلى enum الأدوار — منفصل عن "teacher" عمداً
 * (قرار صريح: الداعم الأكاديمي ليس نوع معلم). نفس أسلوب إضافة دور accountant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','teacher','student','accountant','academic_support') NOT NULL");
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'academic_support')->delete();
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','teacher','student','accountant') NOT NULL");
    }
};
