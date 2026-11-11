<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المدرس الجامعي قد يقدّم باقات دعم أكاديمي يضيفها له الأدمن — عبر ملف داعم مرتبط به
 * (نفس user_id، teacher_id يشير للمدرس). بذلك كل منظومة الدعم (الباقات، الشراء، الدفع،
 * التقرير المالي) تعمل كما هي. الداعم المستقل (موظف الشركة الشريكة) teacher_id = null.
 * عمود جديد فقط — لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_supporters', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->unique()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('academic_supporters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_id');
        });
    }
};
