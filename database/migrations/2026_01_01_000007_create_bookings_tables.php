<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الحجوزات والجلسات
 * ═══════════════════════════════════════════════════════════════
 *
 * bookings   = اشتراك الطالب في باقة (فردية أو مجموعة)
 * enrollments = تسجيل الطالب في دورة تدريبية
 * sessions   = الجلسات الفعلية (BBB) — مشتقة من الحجز/التسجيل
 *
 * ملاحظة الأدمن: يستطيع إنشاء حجز يدوي نيابةً عن طالب (is_manual)
 * مع تسجيل السبب إلزامياً في audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // رقم مرجعي للطالب

            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();

            // ── تجميد الأسعار وقت الحجز (snapshot) ──
            $table->decimal('amount_paid', 10, 2);          // ما دفعه الطالب
            $table->decimal('teacher_amount', 10, 2);       // مستحق المعلم
            $table->decimal('platform_amount', 10, 2);      // عائد المنصة
            $table->decimal('margin_percent_snapshot', 5, 2);
            $table->char('currency', 3)->default('USD');

            // ── رصيد الجلسات ──
            $table->unsignedSmallInteger('sessions_total');
            $table->unsignedSmallInteger('sessions_used')->default(0);
            $table->unsignedSmallInteger('sessions_remaining')->default(0);

            // ── الصلاحية ──
            $table->date('expires_at')->nullable();
            $table->date('frozen_until')->nullable();       // عند تجميد الدورة
            $table->unsignedSmallInteger('extension_days')->default(0);

            // ── السياسة ──
            $table->timestamp('policy_accepted_at')->nullable(); // إلزامي قبل الدفع

            // ── حجز الأدمن اليدوي ──
            $table->boolean('is_manual')->default(false);
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('manual_reason')->nullable();

            $table->enum('status', [
                'pending_teacher_confirmation', // فردية فقط: طلب حجز بانتظار موافقة المعلم على الموعد — لا دفع بعد
                'pending_payment',   // محجوز مؤقتاً 15 دقيقة
                'confirmed',
                'active',            // جلسات جارية
                'completed',
                'cancelled',
                'expired',
            ])->default('pending_payment')->index();

            /**
             * الباقات الفردية فقط: التاريخ/الوقت الذي اختاره الطالب عند تقديم طلب
             * الحجز — تُستخدم لتوليد الجلسات فور موافقة المعلم. فارغة لأي حجز آخر
             * (جماعي/يدوي) لأن جدول الجلسات فيها معروف مسبقاً من package_schedules.
             */
            $table->date('requested_date')->nullable();
            $table->time('requested_start_time')->nullable();

            $table->timestamp('hold_expires_at')->nullable(); // انتهاء الحجز المؤقت
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'status']);
            $table->index(['teacher_id', 'status']);
        });

        // تسجيل الطالب في دورة تدريبية
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();

            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete(); // المركز

            $table->decimal('amount_paid', 10, 2);
            $table->decimal('provider_amount', 10, 2);   // مستحق المركز
            $table->decimal('platform_amount', 10, 2);
            $table->decimal('margin_percent_snapshot', 5, 2);
            $table->char('currency', 3)->default('USD');

            $table->timestamp('policy_accepted_at')->nullable();

            $table->boolean('is_manual')->default(false);
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('manual_reason')->nullable();

            $table->enum('status', [
                'pending_payment', 'confirmed', 'in_progress',
                'completed', 'cancelled', 'withdrawn',
            ])->default('pending_payment')->index();

            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_id', 'course_id']);
        });

        /**
         * الجلسات الفعلية — تُنشأ من الحجز أو التسجيل.
         * غرفة BBB واحدة لكل جلسة.
         */
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();

            // الجلسة تتبع إما حجزاً (باقة) أو تسجيلاً (دورة)
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence_no')->default(1); // الجلسة رقم N

            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_min')->default(60);
            $table->dateTime('original_scheduled_at')->nullable(); // قبل التغيير

            // ── BigBlueButton ──
            $table->string('bbb_meeting_id', 120)->nullable()->unique();
            $table->string('bbb_attendee_pw', 60)->nullable();
            $table->string('bbb_moderator_pw', 60)->nullable();
            $table->text('join_url_student')->nullable();
            $table->text('join_url_teacher')->nullable();
            $table->string('recording_url')->nullable();

            $table->enum('status', [
                'scheduled',
                'reschedule_pending',
                'rescheduled',
                'active',
                'completed',
                'cancelled',
                'suspended',          // أثناء تجميد الدورة
                'no_show_student',
                'no_show_teacher',
            ])->default('scheduled')->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // هل هذه جلسة تعويضية مجانية (بسبب غياب المعلم)
            $table->boolean('is_makeup')->default(false);
            $table->foreignId('makeup_for_session_id')->nullable()
                ->constrained('class_sessions')->nullOnDelete();

            // ملاحظات المعلم الخاصة (لا يراها الطالب)
            $table->text('teacher_notes')->nullable();

            $table->timestamps();

            $table->index(['teacher_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });

        /**
         * حضور الجلسات — many-to-many لأن جلسة المجموعة/الدورة
         * فيها عدة طلاب.
         */
        Schema::create('session_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('attendance', [
                'registered', 'present', 'absent', 'excused', 'partial',
            ])->default('registered')->index();

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            // إشعار مسبق بالغياب (6 ساعات فأكثر)
            $table->timestamp('absence_notified_at')->nullable();
            $table->text('absence_reason')->nullable();

            // هل احتُسبت الجلسة من رصيد الطالب
            $table->boolean('deducted_from_balance')->default(false);

            $table->timestamps();

            $table->unique(['class_session_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_attendees');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('bookings');
    }
};
