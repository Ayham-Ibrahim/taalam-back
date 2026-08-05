<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * جداول الملفات الشخصية
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ قرار معماري: المركز التدريبي ليس كياناً مستقلاً — هو نوع
 *    ثالث من المدرسين داخل جدول teachers نفسه.
 *
 *    teacher_type = 'school'          → يقدّم باقات فقط
 *    teacher_type = 'university'      → يقدّم باقات فقط
 *    teacher_type = 'training_center' → يقدّم دورات فقط
 *
 *    قاعدة صارمة: كل نوع يقدّم شيئاً واحداً — لا خلط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // ── النوع يحدد ما يستطيع تقديمه ──
            $table->enum('teacher_type', ['school', 'university', 'training_center'])
                ->index();

            $table->enum('qualification', [
                'bachelor', 'master', 'phd', 'professional_cert', 'diploma',
            ])->nullable();

            $table->enum('experience_years', [
                'under_1', '1_3', '3_5', 'over_5',
            ])->nullable();

            $table->text('bio')->nullable();
            $table->string('intro_video_path')->nullable();
            $table->unsignedSmallInteger('intro_video_seconds')->nullable();

            // ── حقول خاصة بالمركز التدريبي (nullable للأنواع الأخرى) ──
            $table->string('display_name_en', 180)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('commercial_register', 60)->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();

            // ── حقول التدريس ──
            $table->json('age_groups')->nullable();
            $table->json('teaching_methods')->nullable();

            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->unsignedTinyInteger('max_daily_sessions')->nullable();

            // ── دورة حياة الحساب ──
            $table->enum('status', [
                'created', 'invited', 'active_unverified',
                'pending_verification', 'verified', 'suspended', 'rejected',
            ])->default('created')->index();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            // ── مقاييس الترتيب في البحث ──
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('students_count')->default(0);
            $table->decimal('completion_rate', 5, 2)->default(0);
            $table->unsignedInteger('avg_response_minutes')->nullable();
            $table->decimal('profile_completeness', 5, 2)->default(0);
            $table->decimal('ranking_score', 6, 2)->default(0)->index();

            $table->unsignedTinyInteger('no_show_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'teacher_type']);
        });

        // ── علاقات المدرس بالتصنيفات ──
        Schema::create('teacher_subject', function (Blueprint $table) {
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->primary(['teacher_id', 'subject_id']);
        });

        Schema::create('teacher_curriculum', function (Blueprint $table) {
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->primary(['teacher_id', 'curriculum_id']);
        });

        Schema::create('teacher_language', function (Blueprint $table) {
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->primary(['teacher_id', 'language_id']);
        });

        // ── الطلاب ──
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->enum('education_type', ['school', 'university', 'training'])->index();

            // مدرسي
            $table->foreignId('curriculum_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('grade')->nullable();

            // جامعي
            $table->foreignId('university_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('major_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('academic_level', ['diploma', 'bachelor', 'master'])->nullable();

            // تدريبي
            $table->foreignId('course_field_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('level', ['beginner', 'intermediate', 'advanced'])->nullable();

            $table->date('birth_date')->nullable();
            $table->string('guardian_name', 150)->nullable();
            $table->string('guardian_phone', 25)->nullable();

            // للاستيراد من Excel
            $table->boolean('imported')->default(false);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
        Schema::dropIfExists('teacher_language');
        Schema::dropIfExists('teacher_curriculum');
        Schema::dropIfExists('teacher_subject');
        Schema::dropIfExists('teachers');
    }
};
