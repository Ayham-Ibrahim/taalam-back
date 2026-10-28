<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قائمة الاختبارات المعيارية التي يُحضّر لها المعلم (SAT, ACT, IB, IGCSE...) —
 * تظهر في قسم "التحضير للامتحانات" في بروفايل المعلم العام. مفهوم مستقل عن
 * teaching_methods وعن curricula (منهج دراسي vs اختبار قبول/شهادة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->json('exam_prep')->nullable()->after('teaching_methods');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('exam_prep');
        });
    }
};
