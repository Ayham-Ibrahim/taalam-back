<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * العملة الأساسية للمنصة تتحول من USD إلى AED — يشمل التغيير افتراضي العمود
 * (كل سجل جديد من الآن) وأيضاً السجلات الموجودة فعلاً (بيانات تجريبية/demo
 * فقط حتى الآن، لا معاملات حقيقية) كي لا يبقى النظام بحالة مختلطة بين USD
 * قديمة وAED جديدة. لا تحويل فعلي للأرقام هنا (32 USD لا تصبح ~117 AED) —
 * فقط تبديل تسمية العملة، لأن لا بيانات مالية حقيقية تستحق تحويلاً دقيقاً
 * بسعر صرف وقت الكتابة.
 */
return new class extends Migration
{
    private const TABLES = ['packages', 'courses', 'bookings', 'enrollments', 'payments', 'payouts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY currency CHAR(3) NOT NULL DEFAULT 'AED'");
            DB::table($table)->where('currency', 'USD')->update(['currency' => 'AED']);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} MODIFY currency CHAR(3) NOT NULL DEFAULT 'USD'");
            DB::table($table)->where('currency', 'AED')->update(['currency' => 'USD']);
        }
    }
};
