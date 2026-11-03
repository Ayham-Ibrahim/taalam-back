<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دليل الموافقة على سياسة الدور (student-policy / teacher-policy) عند التسجيل:
 * policy_accepted_at لحظة الموافقة، وpolicy_version = updated_at صفحة السياسة وقتها
 * — يحدد أي نسخة من النص وافق عليها المستخدم إن عدّلها الأدمن لاحقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('policy_accepted_at')->nullable()->after('is_active');
            $table->timestamp('policy_version')->nullable()->after('policy_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['policy_accepted_at', 'policy_version']);
        });
    }
};
