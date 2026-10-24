<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** نسبة ربح الداعم — يحددها الأدمن عند إنشاء الباقة (نظام جديد: الأدمن ينشئ الباقة ويربطها بداعم واحد، بدل أن ينشئها الداعم نفسه) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_packages', function (Blueprint $table) {
            $table->decimal('supporter_profit_percent', 5, 2)->nullable()->after('tier');
        });
    }

    public function down(): void
    {
        Schema::table('support_packages', function (Blueprint $table) {
            $table->dropColumn('supporter_profit_percent');
        });
    }
};
