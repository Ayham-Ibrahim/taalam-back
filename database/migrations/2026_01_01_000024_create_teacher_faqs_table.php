<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أسئلة شائعة يديرها المعلم/المركز بنفسه (أو الأدمن نيابة عنه) عن عمله
 * تحديداً — تظهر في بروفايله العام للطلاب. يوازي teacher_videos تماماً في
 * البنية والصلاحيات (نفس TeacherPolicy::update تُغطّي كليهما).
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
