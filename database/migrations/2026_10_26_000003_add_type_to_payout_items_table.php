<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بند المستحقات إما مستحق جلسة عادي (session)، أو تسوية (settlement) لجلسة دورة أو باقة
 * جماعية صُرفت سابقاً ثم زادت قيمتها بتسجيل/انضمام متأخر — الطالب المتأخر يدفع السعر
 * كاملاً ويُربط بكل جلساتها، فتُضاف حصته من الجلسات المصروفة إلى الدفعة التالية
 * (PayoutService::lateJoinSettlements). الجلسة الواحدة قد تظهر في أكثر من دفعة بهذا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_items', function (Blueprint $table) {
            $table->string('type', 20)->default('session')->after('class_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('payout_items', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
