<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جداول التصنيفات — يديرها الأدمن بالكامل (القسم 9.8)
 * تُستخدم كقوائم منسدلة في نماذج المعلمين والطلاب والفلاتر.
 */
return new class extends Migration
{
    public function up(): void
    {
        // المناهج: MOE / American / British / Cambridge / Edexcel / IB
        Schema::create('curricula', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();     // IG, American, National
            $table->string('name_ar', 100);
            $table->string('name_en', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // المراحل: ابتدائي / متوسط / ثانوي / جامعي
        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_ar', 100);
            $table->enum('education_type', ['school', 'university', 'training'])->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // المواد الدراسية — مرتبطة بنوع التعليم
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->enum('education_type', ['school', 'university', 'training'])->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // ربط المواد بالمراحل (many-to-many)
        Schema::create('subject_stage', function (Blueprint $table) {
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->primary(['subject_id', 'stage_id']);
        });

        // الجامعات
        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 160);
            $table->string('name_en', 160)->nullable();
            $table->string('country', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // التخصصات الجامعية
        Schema::create('majors', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 160);
            $table->string('name_en', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // مجالات الدورات التدريبية
        Schema::create('course_fields', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_ar', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // اللغات
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();  // ar, en, fr
            $table->string('name_ar', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
        Schema::dropIfExists('course_fields');
        Schema::dropIfExists('majors');
        Schema::dropIfExists('universities');
        Schema::dropIfExists('subject_stage');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('stages');
        Schema::dropIfExists('curricula');
    }
};
