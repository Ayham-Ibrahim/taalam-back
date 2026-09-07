<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فيديو تعريفي واحد (رابط يوتيوب) + قائمة فيديوهات إضافية تظهر في بروفايل
 * المعلم للطلاب عبر مشغّل يوتيوب مضمَّن. لا علاقة لهذا بعمودي
 * intro_video_path/intro_video_seconds الموجودين مسبقاً في جدول teachers —
 * كانا مُعدَّين لفيديو مرفوع كملف (مسار تخزين + مدة بالثواني) ولم يُستخدما قط
 * (لا رفع فعلي لهما في أي مكان بالكود)، فبقيا دون تغيير هنا عمداً؛ الفيديوهات
 * هنا روابط يوتيوب خارجية فقط، يخزَّن معرّف الفيديو النظيف (11 حرفاً) بعد
 * استخراجه من الرابط بأي صيغة (watch/youtu.be/shorts/embed) — انظر
 * App\Rules\ValidYoutubeVideo::extractId().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('intro_youtube_id', 20)->nullable()->after('intro_video_seconds');
        });

        Schema::create('teacher_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('youtube_id', 20);
            $table->string('title', 150)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_videos');

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('intro_youtube_id');
        });
    }
};
