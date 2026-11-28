<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المحادثة بين الطالب والمعلم/الداعم الأكاديمي. الطرفان حسابا مستخدمين (لا teacher_id):
 * الداعم المستقل لا يملك سجل معلم، والمدرس الجامعي الذي يُدرّس ويدعم نفس الطالب له
 * محادثة واحدة معه لأن الحسابين نفس user_id. محادثة واحدة لكل زوج (قيد فريد)، تُفتح عند
 * أول تأكيد (حجز/تسجيل/باقة دعم) ولا تُغلق أبداً — الحظر الإداري وحده يوقفها.
 * restrictOnDelete على المستخدمين: الاحتفاظ للأبد، ونفس شبكة الأمان الأخيرة في bookings —
 * المحادثة لا توجد أصلاً إلا لطرفين بينهما علاقة مؤكدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('provider_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['active', 'blocked'])->default('active')->index();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['student_user_id', 'provider_user_id']);
            $table->index('provider_user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // null لرسائل النظام
            $table->foreignId('sender_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('type', ['text', 'system'])->default('text');
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'id']);
            $table->index(['conversation_id', 'sender_id', 'read_at']);
        });

        Schema::create('message_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->text('old_body');
            $table->timestamp('edited_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_edits');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
