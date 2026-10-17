<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** الجامعة التي درس فيها الداعم الأكاديمي وتخصصه — تُعرَضان في بروفايله العام للطالب. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_supporters', function (Blueprint $table) {
            $table->foreignId('university_id')->nullable()->after('experience_years')->constrained()->nullOnDelete();
            $table->foreignId('major_id')->nullable()->after('university_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('academic_supporters', function (Blueprint $table) {
            $table->dropForeign(['university_id']);
            $table->dropForeign(['major_id']);
            $table->dropColumn(['university_id', 'major_id']);
        });
    }
};
