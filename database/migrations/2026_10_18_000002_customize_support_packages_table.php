<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * باقة الدعم تصير قابلة للتخصيص: اسم يختاره الداعم، سعر يدخله يدوياً (لا يعود
 * sncapshot من الإعدادات)، ومواد متعددة بدل مادة واحدة فقط — pivot جديد بدل
 * subject_id. tier يبقى تصنيفاً فقط (أساسية/شاملة) بلا أي علاقة بالسعر بعد الآن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_packages', function (Blueprint $table) {
            $table->string('title', 150)->after('academic_supporter_id');
        });

        Schema::table('support_packages', function (Blueprint $table) {
            $table->dropForeign(['subject_id']);
            $table->dropColumn('subject_id');
        });

        Schema::create('support_package_subject', function (Blueprint $table) {
            $table->foreignId('support_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->primary(['support_package_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_package_subject');

        Schema::table('support_packages', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('academic_supporter_id')->constrained()->restrictOnDelete();
        });

        Schema::table('support_packages', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
