<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * صفحتا سياسة الطالب وسياسة المعلم ضمن legal_pages (نفس عرض/تعديل privacy وterms).
 * insertOrIgnore لا updateOrInsert: لا يُكتب فوق صفحة عدّلها الأدمن إن وُجدت مسبقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pages = require database_path('data/policy_pages.php');

        foreach ($pages as $slug => $sections) {
            DB::table('legal_pages')->insertOrIgnore([
                'slug' => $slug,
                'sections' => json_encode($sections, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('legal_pages')->whereIn('slug', ['student-policy', 'teacher-policy'])->delete();
    }
};
