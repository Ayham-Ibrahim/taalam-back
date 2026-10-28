<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الداعم الأكاديمي — كيان منفصل تماماً عن Teacher (قرار صريح: لا يُعاد استخدام
 * جدول teachers إطلاقاً). موظف شركة شريكة خارجية يدعم طلاباً جامعيين بمهامهم
 * الدراسية عبر باقات دعم بسعر ثابت (support_packages) — لا جلسات/حجوزات/باقات
 * تقليدية، لا وثائق توثيق (الأدمن يضيف الحساب ويصير نشطاً فوراً — AcademicSupporterService::createByAdmin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_supporters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->text('bio')->nullable();
            $table->enum('qualification', [
                'bachelor', 'master', 'phd', 'professional_cert', 'diploma',
            ])->nullable();
            $table->enum('experience_years', [
                'under_1', '1_3', '3_5', 'over_5',
            ])->nullable();

            // لا مرحلة توثيق — الأدمن يضيف الحساب فيصير نشطاً مباشرة. التعليق فقط
            // لإجراء إداري لاحق (مخالفة مثلاً)، لا دورة حياة اعتماد كالمعلم.
            $table->enum('status', ['active', 'suspended'])->default('active')->index();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('academic_supporter_subject', function (Blueprint $table) {
            $table->foreignId('academic_supporter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->primary(['academic_supporter_id', 'subject_id']);
        });

        Schema::create('academic_supporter_language', function (Blueprint $table) {
            $table->foreignId('academic_supporter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->primary(['academic_supporter_id', 'language_id']);
        });

        // غاليري نماذج أعمال — نفس شكل teacher_gallery_items في Phase 1 (محذوف الآن)،
        // لكن منفصل بالكامل هنا. القرص عام (public) — نماذج تسويقية علنية لا وثائق حساسة.
        Schema::create('academic_supporter_gallery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_supporter_id')->constrained()->cascadeOnDelete();
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
        Schema::dropIfExists('academic_supporter_gallery_items');
        Schema::dropIfExists('academic_supporter_language');
        Schema::dropIfExists('academic_supporter_subject');
        Schema::dropIfExists('academic_supporters');
    }
};
