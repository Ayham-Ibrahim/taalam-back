<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كوبون لباقة دعم أكاديمي — نفس نمط Payment (booking_id/enrollment_id/
 * support_package_purchase_id) تماماً: package_id و teacher_id يصيران nullable،
 * support_package_id يُضاف بجانبهما، "واحد فقط من الاثنين محدَّد" يُفرض في كود
 * التطبيق لا بقيد قاعدة بيانات. لا doctrine/dbal مثبّتة — MODIFY خام إلزامي بعد
 * إسقاط الـ foreign key الحالي (لا يمكن تعديل عمود مربوط بـ FK مباشرة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropForeign(['teacher_id']);
        });

        DB::statement('ALTER TABLE coupons MODIFY package_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE coupons MODIFY teacher_id BIGINT UNSIGNED NULL');

        Schema::table('coupons', function (Blueprint $table) {
            $table->foreign('package_id')->references('id')->on('packages')->cascadeOnDelete();
            $table->foreign('teacher_id')->references('id')->on('teachers')->cascadeOnDelete();
            $table->foreignId('support_package_id')->nullable()->after('package_id')->constrained()->cascadeOnDelete();
            $table->index(['support_package_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropForeign(['support_package_id']);
            $table->dropIndex(['support_package_id', 'is_active']);
            $table->dropColumn('support_package_id');
            $table->dropForeign(['package_id']);
            $table->dropForeign(['teacher_id']);
        });

        DB::statement('ALTER TABLE coupons MODIFY package_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE coupons MODIFY teacher_id BIGINT UNSIGNED NOT NULL');

        Schema::table('coupons', function (Blueprint $table) {
            $table->foreign('package_id')->references('id')->on('packages')->cascadeOnDelete();
            $table->foreign('teacher_id')->references('id')->on('teachers')->cascadeOnDelete();
        });
    }
};
