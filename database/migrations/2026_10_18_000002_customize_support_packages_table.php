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

        Schema::create('support_package_subject', function (Blueprint $table) {
            $table->foreignId('support_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->primary(['support_package_id', 'subject_id']);
        });

        // نقل البيانات قبل حذف subject_id — كي لا تفقد الباقات الموجودة مادتها،
        // ونعطيها اسماً افتراضياً من فئتها ومادتها بدل اسم فارغ. يشمل الباقات
        // المحذوفة ناعماً أيضاً (لا شرط على deleted_at) لأنها قد تُستعاد.
        DB::table('support_packages')
            ->leftJoin('subjects', 'subjects.id', '=', 'support_packages.subject_id')
            ->orderBy('support_packages.id')
            ->select('support_packages.id', 'support_packages.subject_id', 'support_packages.tier', 'subjects.name_ar as subject_name')
            ->each(function ($package) {
                if ($package->subject_id) {
                    DB::table('support_package_subject')->insertOrIgnore([
                        'support_package_id' => $package->id,
                        'subject_id' => $package->subject_id,
                    ]);
                }

                $tierLabel = $package->tier === 'premium' ? 'باقة شاملة' : 'باقة أساسية';
                DB::table('support_packages')->where('id', $package->id)->update([
                    'title' => $package->subject_name ? "{$tierLabel} - {$package->subject_name}" : $tierLabel,
                ]);
            });

        Schema::table('support_packages', function (Blueprint $table) {
            $table->dropForeign(['subject_id']);
            $table->dropColumn('subject_id');
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
