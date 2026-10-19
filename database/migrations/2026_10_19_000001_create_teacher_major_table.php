<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تخصصات المعلم الجامعي (قد يدرّس أكثر من تخصص) — يختارها من ملفه الشخصي،
 * ويُفلتَر بها في البحث عند اختيار "تعليم جامعي". جدول جديد فقط، بلا أي مساس
 * ببيانات موجودة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_major', function (Blueprint $table) {
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('major_id')->constrained()->cascadeOnDelete();
            $table->primary(['teacher_id', 'major_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_major');
    }
};
