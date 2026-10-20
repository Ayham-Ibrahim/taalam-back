<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل انضمام المعلم/الطالب لغرفة الجلسة (زر "انضم" عبر GET /class-sessions/{id}/join)
 * — للعرض على الأدمن فقط. جدول جديد منفصل تماماً: لا يُقرأ في أي منطق آخر (حالة
 * الجلسة، session_attendees.attendance، رصيد الحجز، المستحقات) ولا يمسّ أي بيانات موجودة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_join_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['teacher', 'student']);
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('first_joined_at');
            $table->dateTime('last_joined_at');
            $table->unsignedInteger('join_count')->default(1);
            $table->timestamps();

            $table->unique(['class_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_join_logs');
    }
};
