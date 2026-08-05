<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users — الجدول الجذر لكل الهويات في النظام
 *
 * RULE-01: لا يوجد تسجيل ذاتي للمعلمين. الأدمن فقط ينشئ حسابات المعلمين.
 * الطلاب يسجلون ذاتياً. المراكز التدريبية ينشئها الأدمن أيضاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('phone', 25)->nullable()->unique();
            $table->string('whatsapp', 25)->nullable();

            // admin | teacher | student
            // ملاحظة: المركز التدريبي دوره 'teacher' — يتميّز بـ teachers.teacher_type
            $table->enum('role', ['admin', 'teacher', 'student'])
                ->index();

            $table->string('password')->nullable(); // null حتى يفعّل المعلم حسابه
            $table->string('avatar_path')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('locale', 5)->default('ar');
            $table->string('timezone', 64)->default('Asia/Riyadh');

            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_login_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'is_active']);
        });

        // دعوات تفعيل حسابات المعلمين/المراكز — صلاحية 48 ساعة
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->string('token', 100)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['token', 'expires_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('account_invitations');
        Schema::dropIfExists('users');
    }
};
