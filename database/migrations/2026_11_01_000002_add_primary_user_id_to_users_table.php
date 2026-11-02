<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حسابات الإخوة: طالب أساسي (حساب طبيعي بإيميل وكلمة مرور) يضيف إخوته كحسابات
 * طلاب مستقلة تماماً (user + student منفصلان) مرتبطة به عبر primary_user_id.
 * حساب الأخ بلا إيميل ولا كلمة مرور — لا يدخل مباشرة، بل بالتبديل من حساب العائلة.
 *
 * email يصبح nullable لحسابات الإخوة فقط — يبقى unique (MySQL يسمح بعدة NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('primary_user_id')->nullable()->after('role')
                ->constrained('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE users MODIFY email VARCHAR(150) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(150) NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_user_id');
        });
    }
};
