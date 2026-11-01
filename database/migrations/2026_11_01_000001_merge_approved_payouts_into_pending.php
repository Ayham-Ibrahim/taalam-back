<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * للكشف حالتان فقط: غير مدفوع (pending) ومدفوع (paid) — أُزيلت خطوة الاعتماد (PayoutService::markPaid
 * يحوّل من pending مباشرة). الكشوف المعتمدة غير المحوّلة تعود غير مدفوعة كي يبقى زر التحويل متاحاً لها؛
 * approved_at/approved_by يبقيان كسجل تاريخي. لا رجوع: لا يُعرف أي pending كان approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payouts')->whereIn('status', ['approved', 'processing', 'on_hold'])->update(['status' => 'pending']);
    }

    public function down(): void
    {
        //
    }
};
