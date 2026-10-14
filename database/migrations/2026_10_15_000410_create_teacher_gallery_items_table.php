<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * غاليري نماذج أعمال/اساينمتس للداعم الأكاديمي (Teacher::isAcademicSupport) —
 * تُعرَض في بروفايله العام، قابلة للتنزيل أو المعاينة. يوازي teacher_videos/
 * teacher_faqs في البنية والصلاحيات (نفس TeacherPolicy::update)، لكن القرص
 * عام (public) بلا توقيع مؤقت — نماذج تسويقية علنية لا وثائق حساسة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_gallery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->enum('file_type', ['image', 'pdf', 'pptx', 'xlsx']);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_gallery_items');
    }
};
