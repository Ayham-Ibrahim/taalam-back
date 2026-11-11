<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رابط تقييم جودة الجلسة صار برمز عشوائي (/rate-session/{token}) بدل رقم الجلسة الظاهر —
 * لا يُخمَّن ولا يكشف رقم الجلسة، ويمكن إبطاله بحذفه. عمود جديد فقط — لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->string('quality_rating_token', 64)->nullable()->unique()->after('quality_rating_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropUnique(['quality_rating_token']);
            $table->dropColumn('quality_rating_token');
        });
    }
};
