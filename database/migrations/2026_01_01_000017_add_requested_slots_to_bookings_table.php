<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * باقة فردية بعدة جلسات (sessions_count > 1) كانت تجبر الطالب على اختيار
 * تاريخ/وقت واحد يتكرر أسبوعياً تلقائياً لكل الجلسات — لا يمكنه اختيار موعد
 * مستقل لكل جلسة. requested_date/requested_start_time (عمودان مفردان) لا
 * يتّسعان لأكثر من موعد واحد أصلاً، فهذا العمود الجديد يحمل مصفوفة
 * {date, start_time} بعدد يساوي sessions_count بالضبط. الأعمدة القديمة تبقى
 * كما هي (تُملأ بأول موعد فقط، توافقاً مع أي كود لم يُحدَّث بعد) — لا حذف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('requested_slots')->nullable()->after('requested_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('requested_slots');
        });
    }
};
