<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * طلبات تغيير المواعيد — موافقة الأدمن إلزامية دائماً
 * ═══════════════════════════════════════════════════════════════
 *
 * قرار محسوم: لا يوجد تغيير تلقائي إطلاقاً.
 * الفرق بين ما قبل/بعد 24 ساعة هو الأولوية وإلزامية السبب فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reschedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();

            // من قدّم الطلب
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('requester_role', ['student', 'teacher'])->index();

            // ── المواعيد ──
            $table->dateTime('current_scheduled_at');
            $table->dateTime('proposed_scheduled_at');
            $table->dateTime('admin_alternative_at')->nullable(); // موعد بديل يقترحه الأدمن

            // ── السياق الزمني ──
            $table->boolean('within_free_window')->default(false); // خلال 24 ساعة من الحجز
            $table->unsignedInteger('hours_before_session')->nullable();
            $table->text('reason')->nullable(); // إلزامي إن كان خارج النافذة

            // ── الحالة — تبدأ دائماً pending ──
            $table->enum('status', [
                'pending',
                'approved',
                'approved_with_alternative',
                'rejected',
                'cancelled_by_requester',
                'expired',
            ])->default('pending')->index();

            // ── موافقة الطرف الآخر (عند اقتراح المعلم) ──
            $table->enum('counterparty_response', ['pending', 'accepted', 'declined', 'timeout'])
                ->nullable();
            $table->timestamp('counterparty_deadline')->nullable(); // 12 ساعة
            $table->timestamp('counterparty_responded_at')->nullable();

            // ── قرار الأدمن ──
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->text('rejection_reason')->nullable();

            // SLA — 12 ساعة لرد الأدمن
            $table->timestamp('sla_due_at')->nullable();
            $table->boolean('escalated')->default(false);

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        /**
         * طلبات التغيير على مستوى الدورة/الباقة كاملة
         * (إعادة جدولة، تجميد، استبدال مدرب، تغيير طريقة التقديم)
         */
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();

            // الهدف: Course | Package
            $table->morphs('changeable');

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();

            $table->enum('type', [
                'reschedule_series',   // إعادة جدولة الجلسات القادمة
                'freeze',              // تجميد مؤقت
                'replace_trainer',     // استبدال مدرب — مرة واحدة فقط
                'change_delivery',     // أونلاين ⇄ حضوري
                'extend_sessions',     // إضافة جلسات بسعر
                'split_session',       // تقسيم جلسة
                'add_assessment',      // جلسة تقييم إضافية
            ])->index();

            $table->json('payload'); // تفاصيل الطلب حسب النوع
            $table->text('reason');

            // للتجميد
            $table->date('freeze_start')->nullable();
            $table->date('freeze_end')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])
                ->default('pending')->index();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();

            // مهلة اعتراض الطلاب (48 ساعة)
            $table->timestamp('objection_deadline')->nullable();
            $table->unsignedSmallInteger('objections_count')->default(0);

            $table->timestamps();
        });

        // اعتراضات الطلاب على تغيير جدول دورة
        Schema::create('change_objections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->enum('resolution', ['pending', 'accommodated', 'declined'])
                ->default('pending');
            $table->timestamps();

            $table->unique(['change_request_id', 'student_id']);
        });

        // الشكاوى
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();

            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_session_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('category', [
                'session_quality', 'teacher_no_show', 'technical_issue',
                'payment_issue', 'schedule_issue', 'other',
            ])->index();

            $table->text('description');

            $table->enum('status', ['open', 'in_review', 'resolved', 'closed', 'escalated'])
                ->default('open')->index();

            $table->enum('resolution_type', [
                'makeup_session', 'credit', 'no_action', 'warning_issued',
            ])->nullable();

            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamp('sla_due_at')->nullable(); // 24 ساعة

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('change_objections');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('reschedule_requests');
    }
};
