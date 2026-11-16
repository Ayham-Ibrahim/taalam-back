<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرفقات الجلسة — تبادل الملفات (واجب، شرح، حل) بين المعلم والطالب مباشرة من صفحة
 * الجلسة بدل تمريرها عبر موظف. الملف على قرص خاص (RULE-06) ويُفتح برابط موقّع مؤقت.
 * uploader_role يحدد من يرى الملف: ملف المعلم/الأدمن لكل طلاب الجلسة، وملف الطالب
 * للمعلم والأدمن وصاحبه فقط (لا لبقية طلاب جلسة المجموعة). جدول جديد فقط — لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->enum('uploader_role', ['admin', 'teacher', 'student']);
            $table->string('title', 150)->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index(['class_session_id', 'uploader_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_attachments');
    }
};
