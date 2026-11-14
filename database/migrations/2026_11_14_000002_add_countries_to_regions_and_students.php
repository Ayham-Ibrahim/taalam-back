<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الموقع مستويان قابلان للتوسع: دولة ← ولاية، بدل ولايات إماراتية فقط (regions.country
 * النصّي). دولة بلا ولايات مُعرَّفة مقبولة — يختار طالبها الدولة وحدها.
 * students.country_id مخزَّن بجانب region_id (لا مشتق منه فقط) لهذا السبب تحديداً، ويضبطه
 * Student::saving تلقائياً من الولاية كي لا يختلفا.
 */
return new class extends Migration
{
    private const COUNTRIES = [
        ['code' => 'AE', 'name_ar' => 'الإمارات', 'name_en' => 'United Arab Emirates'],
        ['code' => 'SA', 'name_ar' => 'السعودية', 'name_en' => 'Saudi Arabia'],
        ['code' => 'KW', 'name_ar' => 'الكويت', 'name_en' => 'Kuwait'],
        ['code' => 'QA', 'name_ar' => 'قطر', 'name_en' => 'Qatar'],
        ['code' => 'BH', 'name_ar' => 'البحرين', 'name_en' => 'Bahrain'],
        ['code' => 'OM', 'name_ar' => 'عُمان', 'name_en' => 'Oman'],
    ];

    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique(); // ISO 3166-1 alpha-2
            $table->string('name_ar', 100);
            $table->string('name_en', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::table('countries')->insert(array_map(fn (array $country, int $i) => [
            ...$country,
            'sort_order' => $i + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], self::COUNTRIES, array_keys(self::COUNTRIES)));

        Schema::table('regions', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // الولايات الموجودة كلها إماراتية (regions.country = 'AE')
        DB::table('regions')->update([
            'country_id' => DB::table('countries')->where('code', 'AE')->value('id'),
        ]);

        Schema::table('regions', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable(false)->change();
            // رمز الولاية فريد داخل دولتها فقط — دولتان قد تتشاركان رمزاً
            $table->dropUnique(['code']);
            $table->unique(['country_id', 'code']);
            $table->dropColumn('country');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('country')->constrained()->nullOnDelete();
        });

        DB::table('students')
            ->join('regions', 'regions.id', '=', 'students.region_id')
            ->update(['students.country_id' => DB::raw('regions.country_id')]);
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->string('country', 80)->nullable()->after('name_en');
        });

        DB::table('regions')
            ->join('countries', 'countries.id', '=', 'regions.country_id')
            ->update(['regions.country' => DB::raw('countries.code')]);

        // المفتاح الأجنبي أولاً — MySQL يرفض حذف الفهرس (country_id, code) ما دام المفتاح يستند إليه
        Schema::table('regions', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropUnique(['country_id', 'code']);
            $table->dropColumn('country_id');
            $table->unique('code');
        });

        Schema::dropIfExists('countries');
    }
};
