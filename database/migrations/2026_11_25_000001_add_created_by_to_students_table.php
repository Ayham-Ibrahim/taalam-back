<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * من أنشأ حساب الطالب: null = سجّل بنفسه (تسجيل ذاتي/حملة/حجز تجربة كزائر)، وإلا الأدمن
 * (يدوياً أو باستيراد) أو الحساب الأساسي لأخ. القديم يُستنتج من imported_by وprimary_user_id
 * وسجل التدقيق student.account_created (createByAdmin لم يكن يحفظ منشئه قبل هذا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('imported_by')->constrained('users')->nullOnDelete();
        });

        DB::table('audit_logs')
            ->where('action', 'student.account_created')
            ->where('model_type', 'App\\Models\\Student')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get(['model_id', 'user_id'])
            ->each(fn ($log) => DB::table('students')
                ->where('id', $log->model_id)
                ->whereNull('created_by')
                ->update(['created_by' => $log->user_id]));

        DB::table('students')
            ->whereNull('created_by')
            ->whereNotNull('imported_by')
            ->update(['created_by' => DB::raw('imported_by')]);

        DB::table('students')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->whereNull('students.created_by')
            ->whereNotNull('users.primary_user_id')
            ->get(['students.id', 'users.primary_user_id'])
            ->each(fn ($row) => DB::table('students')
                ->where('id', $row->id)
                ->update(['created_by' => $row->primary_user_id]));
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
