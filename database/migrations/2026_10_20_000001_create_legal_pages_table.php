<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محتوى طويل ومُهيكَل (أقسام) قابل للتعديل من الأدمن — سياسة الخصوصية والشروط
 * والأحكام. جدول مخصّص بدل جدول settings العام: قيمة settings محدودة بـ5000
 * حرف وواجهتها حقل نصي بسطر واحد، غير مناسبين إطلاقاً لمستند قانوني متعدد الأقسام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // 'privacy' | 'terms'
            $table->json('sections'); // [{ title_ar, title_en, body_ar, body_en }, ...]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
    }
};
