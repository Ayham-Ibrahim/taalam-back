<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** إضافة ملفات Word كنوع ملف مسموح بغاليري نماذج الأعمال تبع الداعم الأكاديمي — راجع AddAcademicSupporterGalleryItemRequest */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE academic_supporter_gallery_items MODIFY COLUMN file_type ENUM('image', 'pdf', 'pptx', 'xlsx', 'docx') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE academic_supporter_gallery_items MODIFY COLUMN file_type ENUM('image', 'pdf', 'pptx', 'xlsx') NOT NULL");
    }
};
