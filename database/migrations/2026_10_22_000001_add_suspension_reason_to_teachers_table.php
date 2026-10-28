<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** سبب التعليق — يُحفَظ عند TeacherService::suspend() ويُعرَض للمعلم نفسه عند محاولة الدخول، ويُصفَّر عند reactivate() */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->text('suspension_reason')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('suspension_reason');
        });
    }
};
