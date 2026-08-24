<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تتبّع عمليات استيراد الطلاب/المعلمين الجماعية — كانت تُعالَج بالكامل داخل
 * طلب HTTP نفسه (يتجاوز مهلة المتصفح لآلاف الصفوف)، أصبحت الآن تُدفَع لطابور
 * (ProcessStudentImportJob/ProcessTeacherImportJob) وهذا الجدول هو الحالة
 * الوحيدة التي يعرضها الأدمن لمتابعة تقدّمها بدل الانتظار أمام الشاشة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['student', 'teacher'])->index();
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued')->index();
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('errors')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
