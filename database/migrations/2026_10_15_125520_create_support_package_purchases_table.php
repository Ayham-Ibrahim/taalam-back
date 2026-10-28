<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شراء طالب جامعي لباقة دعم — تدفّق دفع حقيقي عبر Stripe (نظير Booking/Enrollment
 * المبسّط: لا جلسات/BBB، فقط اشتراك الطالب بخدمة الداعم لهذه المادة تحديداً).
 * amount/currency ثابتان وقت الشراء (snapshot من support_packages.student_price)
 * فلا يتأثر سجل الشراء التاريخي لو تغيّر سعر الإعداد لاحقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_package_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_supporter_id')->constrained()->restrictOnDelete();
            $table->foreignId('support_package_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('AED');

            $table->enum('status', ['pending_payment', 'confirmed', 'expired'])
                ->default('pending_payment')
                ->index();

            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['academic_supporter_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_package_purchases');
    }
};
