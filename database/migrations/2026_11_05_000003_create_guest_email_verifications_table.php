<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رموز التحقق (OTP) لبريد الزائر قبل حجز الباقة المجانية بلا حساب. الرمز يُخزَّن مشفّراً،
 * صالح لدقائق، بعدد محاولات محدود، ويُستهلك مرة واحدة (used_at) عند نجاح الحجز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_email_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150);
            $table->string('normalized_email', 150)->index();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_email_verifications');
    }
};
