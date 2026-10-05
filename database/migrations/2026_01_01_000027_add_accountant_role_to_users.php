<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * يضيف دور "accountant" (محاسب) إلى enum الأدوار. الصلاحيات المالية فقط
 * (المستحقات والحجوزات/المدفوعات) — لا يرى باقي لوحة الأدمن.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','teacher','student','accountant') NOT NULL");
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'accountant')->update(['role' => 'admin']);
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','teacher','student') NOT NULL");
    }
};
