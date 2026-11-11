<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلب تقييم جودة الجلسة صار يُرسَل عند بدء الجلسة ودخول المعلم والطالب إليها (لا عند انتهائها) —
 * لكل طالب لحظة دخوله (طالب الجلسة الجماعية المتأخر يصله طلبه أيضاً). هذا العمود يمنع تكرار
 * إرساله لنفس الطالب، و class_sessions.quality_rating_requested_at صار لإشعار المعلم مرة واحدة.
 * عمود جديد فقط — لا يمس أي بيانات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_attendees', function (Blueprint $table) {
            $table->timestamp('quality_rating_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('session_attendees', function (Blueprint $table) {
            $table->dropColumn('quality_rating_requested_at');
        });
    }
};
