<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الإبلاغ من داخل المحادثة يُنشئ شكوى مرتبطة بها (conversation_id) — هذا الربط وحده
 * ما يسمح للأدمن بالاطلاع على المحادثة، لأن student_id/teacher_id تحدد مقدّم الشكوى لا
 * طرفي العلاقة. filed_by_user_id: الداعم المستقل لا يملك student_id ولا teacher_id، فكان
 * قيد chk_complaints_filer سيمنعه من الإبلاغ — القيد يقبله الآن كمقدّم شكوى ثالث.
 * MODIFY COLUMN خام لنفس سبب add_teacher_support_to_complaints_table (لا doctrine/dbal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('filed_by_user_id')->nullable()->after('teacher_id')
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('conversation_id')->nullable()->after('class_session_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('reported_message_id')->nullable()->after('conversation_id')
                ->constrained('messages')->nullOnDelete();
        });

        DB::statement("
            ALTER TABLE complaints MODIFY category ENUM(
                'session_quality', 'teacher_no_show', 'technical_issue',
                'payment_issue', 'schedule_issue', 'other', 'chat_report'
            ) NOT NULL
        ");

        DB::statement('ALTER TABLE complaints DROP CONSTRAINT chk_complaints_filer');
        DB::statement('
            ALTER TABLE complaints
            ADD CONSTRAINT chk_complaints_filer
            CHECK (student_id IS NOT NULL OR teacher_id IS NOT NULL OR filed_by_user_id IS NOT NULL)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE complaints DROP CONSTRAINT chk_complaints_filer');
        DB::statement('
            ALTER TABLE complaints
            ADD CONSTRAINT chk_complaints_filer
            CHECK (student_id IS NOT NULL OR teacher_id IS NOT NULL)
        ');

        DB::statement("
            ALTER TABLE complaints MODIFY category ENUM(
                'session_quality', 'teacher_no_show', 'technical_issue',
                'payment_issue', 'schedule_issue', 'other'
            ) NOT NULL
        ");

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reported_message_id');
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropConstrainedForeignId('filed_by_user_id');
        });
    }
};
