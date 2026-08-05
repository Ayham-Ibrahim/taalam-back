<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التقييمات، الإشعارات، سجل التدقيق، الإعدادات، المواد التعليمية.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * التقييمات — لا تقييم دون جلسة مكتملة حقيقية.
         * نافذة التقييم 7 أيام، والتعديل 24 ساعة.
         */
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // المُقيَّم: حساب مدرس (يشمل المركز التدريبي)
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();

            $table->foreignId('class_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('rating'); // 1..5
            $table->text('comment')->nullable();

            // رد المعلم — مرة واحدة فقط
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();

            // إخفاء من الأدمن فقط
            $table->boolean('is_hidden')->default(false)->index();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('hidden_reason')->nullable();

            // بلاغ عن تقييم مسيء
            $table->boolean('is_reported')->default(false);
            $table->text('report_reason')->nullable();

            $table->timestamp('edit_deadline')->nullable(); // 24 ساعة من الإرسال

            $table->timestamps();

            $table->unique(['student_id', 'class_session_id']);
            $table->index(['teacher_id', 'is_hidden']);
        });

        /**
         * الإشعارات — Laravel Notifications (database channel)
         */
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // سجل الإشعارات المُرسَلة (بريد/داخلي) للتتبع
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 80)->index();   // booking_confirmed, doc_approved...
            $table->enum('channel', ['email', 'database', 'sms', 'whatsapp']);
            $table->string('subject')->nullable();
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        /**
         * RULE-05: كل إجراء حساس يُسجَّل — من، ماذا، متى.
         */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100)->index();  // package.approved, booking.manual_created
            $table->string('model_type', 120)->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['model_type', 'model_id']);
            $table->index(['user_id', 'created_at']);
        });

        /**
         * إعدادات النظام — قابلة للتعديل من الأدمن.
         * تشمل: النسبة الافتراضية، المهل، نوافذ السياسات.
         */
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'integer', 'decimal', 'boolean', 'json'])
                ->default('string');
            $table->string('group', 50)->default('general')->index();
            $table->string('label_ar', 160)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->timestamps();
        });

        /**
         * المواد التعليمية — المعلم يرفع ملفات للطلاب.
         * (من ملاحظة القسم 9.8: المعلم يمكنه رفع مستندات الكورس)
         */
        Schema::create('learning_materials', function (Blueprint $table) {
            $table->id();

            // مرتبطة بباقة أو دورة أو جلسة محددة
            $table->morphs('materialable'); // Package | Course | ClassSession

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('s3_path');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->boolean('is_visible')->default(true);
            $table->timestamp('available_from')->nullable();

            $table->timestamps();
        });

        // المفضلة
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->morphs('favoritable'); // Teacher | Course
            $table->timestamps();

            $table->unique(['student_id', 'favoritable_type', 'favoritable_id'], 'fav_unique');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('learning_materials');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('reviews');
    }
};
