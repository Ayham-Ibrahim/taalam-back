<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الدورات التدريبية
 * ═══════════════════════════════════════════════════════════════
 *
 * تُنشأ حصرياً من حساب teacher_type = 'training_center'.
 * لا يوجد جدول مراكز منفصل — المركز هو teacher بنوع مختلف.
 *
 * الفرق الجوهري عن الباقات:
 *   - جدول صارم غير قابل للتعديل من الطالب إطلاقاً
 *   - تاريخ بداية ونهاية محددان
 *   - نفس آلية التسعير: المركز يضع السعر → الأدمن يحدد نسبته عند الموافقة
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();

            // المالك: حساب مدرس من نوع training_center
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('course_field_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('level', ['beginner', 'intermediate', 'advanced'])->nullable();
            $table->enum('delivery_mode', ['online', 'onsite', 'hybrid', 'recorded'])
                ->default('online');
            $table->string('location')->nullable();

            // ── الجدول الصارم ──
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('total_sessions');
            $table->unsignedSmallInteger('session_duration_min')->default(60);
            $table->unsignedSmallInteger('total_hours')->nullable();

            // ── المقاعد ──
            $table->unsignedSmallInteger('max_seats');
            $table->unsignedSmallInteger('enrolled_count')->default(0);

            // ═══════════ التسعير — نفس آلية الباقات ═══════════
            $table->decimal('provider_price', 10, 2);                      // سعر المركز
            $table->decimal('platform_margin_percent', 5, 2)->nullable();  // يحدده الأدمن
            $table->decimal('student_price', 10, 2)->nullable();           // محسوب ومُجمَّد
            $table->decimal('platform_revenue', 10, 2)->nullable();
            $table->char('currency', 3)->default('USD');

            // ── الشهادة ──
            $table->boolean('has_certificate')->default(false);
            $table->string('certificate_type', 120)->nullable();
            $table->string('certificate_issuer', 160)->nullable();
            $table->text('certificate_requirements')->nullable();

            /**
             * أسئلة بنيوية إلزامية (Boolean) — لا تعتمد على جودة/اكتمال حقل description
             * الحر؛ كل مركز يجيب عليها صراحةً عند إنشاء الدورة حتى يرى المتدرب هذه
             * الحقائق الأساسية دائماً بشكل موحّد بصرف النظر عن دقة الوصف المكتوب.
             */
            $table->boolean('requires_laptop');           // يحتاج الطالب لإحضار جهاز كمبيوتر خاص
            $table->boolean('materials_included');        // المواد التدريبية متوفرة ضمن السعر
            $table->boolean('has_practical_exercises');   // تتضمن الدورة تطبيقات عملية
            $table->boolean('sessions_recorded');         // الجلسات مسجلة ومتاحة للمراجعة لاحقاً

            $table->text('prerequisites')->nullable();
            $table->text('cancellation_policy')->nullable();

            // ── دورة الموافقة ──
            $table->enum('status', [
                'draft', 'pending_approval', 'active', 'full',
                'rejected', 'disabled', 'in_progress', 'completed', 'archived',
            ])->default('draft')->index();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'start_date']);
            $table->index(['teacher_id', 'status']);
        });

        // المناهج المستهدفة بالدورة (اختياري)
        Schema::create('course_curriculum', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->primary(['course_id', 'curriculum_id']);
        });

        // جدول الدورة الثابت — أيام وأوقات متكررة
        Schema::create('course_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('day_of_week'); // 0=الأحد .. 6=السبت
            $table->time('start_time');
            $table->time('end_time');

            $table->timestamps();

            $table->index(['course_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_schedules');
        Schema::dropIfExists('course_curriculum');
        Schema::dropIfExists('courses');
    }
};
