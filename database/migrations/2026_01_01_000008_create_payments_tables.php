<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المدفوعات ومستحقات المعلمين. لا استرداد في هذا النظام — الإلغاء إجراء إداري
 * بلا مقابل مالي تلقائي.
 * الدفع عبر Stripe — التحقق من التوقيع إلزامي على الـ webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // الدفعة تخص إما حجزاً أو تسجيل دورة
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('student_id')->constrained()->restrictOnDelete();

            // ── Stripe ──
            $table->string('stripe_session_id', 255)->nullable()->index();
            $table->string('stripe_payment_intent', 255)->nullable()->index();
            $table->string('stripe_charge_id', 255)->nullable();

            $table->decimal('amount', 10, 2);
            $table->decimal('provider_amount', 10, 2)->nullable(); // مستحق المدرس/المركز
            $table->decimal('platform_amount', 10, 2)->nullable();
            $table->char('currency', 3)->default('USD');

            $table->enum('method', ['stripe', 'manual', 'wallet'])->default('stripe');

            $table->enum('status', [
                'pending', 'processing', 'paid', 'failed',
            ])->default('pending')->index();

            $table->timestamp('paid_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('gateway_payload')->nullable(); // نسخة من رد Stripe

            // فاتورة PDF
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->string('invoice_path')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'status']);
        });

        /**
         * مستحقات المعلمين — تُجمَّع من الجلسات المكتملة.
         * المعلم لا يرى عائد المنصة، فقط مستحقاته.
         */
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();

            // المستفيد: حساب مدرس (مدرسي/جامعي/مركز تدريبي)
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('gross_amount', 12, 2);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->char('currency', 3)->default('USD');

            $table->unsignedSmallInteger('sessions_count')->default(0);

            $table->enum('status', ['pending', 'approved', 'processing', 'paid', 'on_hold'])
                ->default('pending')->index();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('transfer_reference', 120)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        // تفاصيل المستحقات — أي جلسة ساهمت في أي دفعة
        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['payout_id', 'class_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payments');
    }
};
