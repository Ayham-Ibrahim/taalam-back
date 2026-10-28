<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أسئلة شائعة يكتبها المعلم بنفسه من لوحته وتظهر في بروفايله العام للطلاب —
 * سؤال/جواب نصّيان فقط، بلا أي منطق. نفس نمط teacher_videos تماماً (مسار
 * إضافة/تعديل/حذف مستقل، حد أقصى للعدد على مستوى الخدمة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('question', 300);
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_faqs');
    }
};
