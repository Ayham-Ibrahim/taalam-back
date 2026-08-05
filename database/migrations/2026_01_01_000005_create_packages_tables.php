<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الباقات — جوهر نموذج العمل
 * ═══════════════════════════════════════════════════════════════
 *
 * آلية التسعير (قرار معماري محسوم):
 *   1. المعلم يضع سعره:            teacher_price
 *   2. يرسل الباقة للمراجعة:       status = pending_approval
 *   3. الأدمن يحدد نسبته:          platform_margin_percent  ← عند الموافقة
 *   4. النظام يحسب ويُجمّد السعر:   student_price = teacher_price × (1 + margin/100)
 *   5. الباقة تصبح:                status = active
 *
 * ⚠️ student_price و platform_margin_percent يُجمَّدان (snapshot) بعد الموافقة.
 *    أي تعديل لاحق على السعر يستلزم باقة جديدة تمر بالموافقة من البداية.
 *
 * أنماط الجلسات:
 *   individual → النصاب 1، الطالب يختار موعده من availability_slots
 *   group      → النصاب N، جدول ثابت مشترك في package_schedules
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();

            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();

            // ── نمط الجلسة والنصاب ──
            $table->enum('session_format', ['individual', 'group'])->index();
            $table->unsignedSmallInteger('capacity')->default(1); // 1 للفردي، N للمجموعة
            $table->unsignedSmallInteger('enrolled_count')->default(0);

            // ── تفاصيل الجلسات ──
            $table->unsignedSmallInteger('sessions_count');       // عدد الجلسات في الباقة
            $table->unsignedSmallInteger('session_duration_min')->default(60);
            $table->unsignedSmallInteger('validity_days')->nullable(); // صلاحية الباقة

            // ═══════════ التسعير ═══════════
            // سعر المعلم — يُدخله المعلم
            $table->decimal('teacher_price', 10, 2);

            // نسبة المنصة — يحددها الأدمن عند الموافقة (nullable حتى ذلك الحين)
            $table->decimal('platform_margin_percent', 5, 2)->nullable();

            // السعر النهائي — يُحسب ويُجمَّد عند الموافقة
            $table->decimal('student_price', 10, 2)->nullable();

            // عائد المنصة المحسوب = student_price - teacher_price
            $table->decimal('platform_revenue', 10, 2)->nullable();

            $table->char('currency', 3)->default('USD');
            $table->decimal('discount_percent', 5, 2)->nullable(); // خصم معروض للطالب

            // ── دورة الموافقة ──
            $table->enum('status', [
                'draft',             // المعلم أنشأها ولم يرسلها
                'pending_approval',  // بانتظار الأدمن
                'active',            // معتمدة وظاهرة
                'full',              // اكتمل النصاب
                'rejected',          // رُفضت
                'disabled',          // معطّلة
                'archived',
            ])->default('draft')->index();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'session_format']);
            $table->index(['teacher_id', 'status']);
        });

        // المناهج المستهدفة بالباقة (IG / American / National)
        Schema::create('package_curriculum', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->primary(['package_id', 'curriculum_id']);
        });

        // المراحل المستهدفة
        Schema::create('package_stage', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained()->cascadeOnDelete();
            $table->primary(['package_id', 'stage_id']);
        });

        /**
         * جدول الباقة الثابت:
         *   جماعية → المعلم يحدد تاريخ أول جلسة ووقتها صراحةً (date+start_time+end_time)؛
         *            الجلسات التالية تتكرر أسبوعياً بدءاً من هذا التاريخ. الطالب لا يختار
         *            شيئاً — فقط ينضم لما حدده المعلم.
         *   فردية   → المعلم يحدد فقط الأيام المتاحة لهذه الباقة (day_of_week، مجموعة فرعية
         *            من availability_slots الخاصة به) — بلا تاريخ أو وقت. الطالب يختار
         *            تاريخاً ووقتاً ضمن هذه الأيام ويقدّم طلب حجز يوافق عليه المعلم صراحةً
         *            (bookings.status = pending_teacher_confirmation).
         * أي تعديل لاحق على موعد جلسة مؤكّدة يمر عبر آلية طلبات تغيير الموعد
         * (reschedule_requests) الموجودة أصلاً.
         */
        Schema::create('package_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            $table->date('date')->nullable(); // تاريخ أول تكرار (جماعية فقط) — والأيام التالية تُشتق منه أسبوعياً
            $table->unsignedTinyInteger('day_of_week'); // جماعية: مشتق من date. فردية: يدخله المعلم مباشرة
            $table->time('start_time')->nullable(); // فردية: لا وقت على مستوى الباقة إطلاقاً
            $table->time('end_time')->nullable();

            $table->timestamps();

            $table->index(['package_id', 'day_of_week']);
        });

        /**
         * أيام توفّر المعلم العامة — بدون أوقات (المدرس يحدد الأيام فقط). عند
         * إنشاء باقة فردية، يختار المعلم مجموعة فرعية من هذه الأيام لها
         * (package_schedules.day_of_week). الطالب لاحقاً يحدد التاريخ والوقت
         * ضمن هذه الأيام ويقدّم طلب حجز يوافق عليه المعلم صراحةً.
         */
        Schema::create('availability_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('day_of_week');

            $table->timestamps();

            $table->unique(['teacher_id', 'day_of_week']);
        });

        // أيام الإجازة / الإغلاق
        Schema::create('teacher_blackouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_blackouts');
        Schema::dropIfExists('availability_slots');
        Schema::dropIfExists('package_schedules');
        Schema::dropIfExists('package_stage');
        Schema::dropIfExists('package_curriculum');
        Schema::dropIfExists('packages');
    }
};
