<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وثائق التوثيق والشارات.
 *
 * ملاحظة: بما أن المركز التدريبي هو teacher بنوع مختلف،
 * لا حاجة لعلاقات polymorphic — foreign key مباشر يكفي.
 *
 * RULE-06: الملفات لا تُخزَّن على الخادم — S3 فقط، والوصول عبر Signed URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();

            $table->enum('type', [
                'identity',        // إثبات هوية — إلزامي
                'academic',        // شهادة أكاديمية — إلزامي
                'experience',      // خبرة — اختياري
                'professional',    // شهادة مهنية — اختياري
                'security',        // فحص أمني — للتعليم المدرسي
                'commercial',      // سجل تجاري — للمراكز التدريبية
            ])->index();

            $table->string('s3_path');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')->index();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['teacher_id', 'status']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_ar', 100);
            $table->string('description_ar')->nullable();
            $table->string('icon', 50)->nullable();
            $table->boolean('is_auto')->default(false);
            $table->string('auto_document_type', 30)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('badge_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();

            $table->timestamps();

            $table->index(['teacher_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_awards');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('verification_documents');
    }
};
