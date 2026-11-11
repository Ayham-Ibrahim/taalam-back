<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تقييم جودة الجلسة — منفصل تماماً عن تقييمات المعلم العامة (reviews): يظهر للأدمن فقط،
 * غايته معرفة جودة الجلسة الفعلية (الصوت والصورة، الاتصال، طريقة الإعطاء، التقييم العام،
 * مدى الاستفادة). طلب التقييم يُرسَل تلقائياً فور انتهاء الجلسة (quality_rating_requested_at
 * يمنع تكراره). جدول جديد وعمود جديد فقط — لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_quality_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('audio_video_quality');
            $table->unsignedTinyInteger('connection_stability');
            $table->unsignedTinyInteger('teaching_quality');
            $table->unsignedTinyInteger('overall_rating');
            $table->unsignedTinyInteger('benefit_level');
            $table->text('comment')->nullable();
            $table->timestamps();

            // تقييم واحد لكل طالب عن كل جلسة (يُعدَّل لا يُكرَّر)
            $table->unique(['class_session_id', 'student_id']);
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->timestamp('quality_rating_requested_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('quality_rating_requested_at');
        });

        Schema::dropIfExists('session_quality_ratings');
    }
};
