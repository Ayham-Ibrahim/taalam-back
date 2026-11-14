<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الولايات (الإمارات السبع مبدئياً) — قائمة ثابتة يديرها الأدمن من التصنيفات
 * بدل students.city النصّي الحر الذي لا يصلح للتجميع ("دبي"/"Dubai"/"دبى").
 * تغذي تقارير التسويق: توزيع الطلاب حسب الولاية × المنهاج.
 * مطابقة city القديمة مع الولايات تتم بأمر students:match-regions لا هنا.
 */
return new class extends Migration
{
    private const EMIRATES = [
        ['code' => 'AUH', 'name_ar' => 'أبوظبي', 'name_en' => 'Abu Dhabi'],
        ['code' => 'DXB', 'name_ar' => 'دبي', 'name_en' => 'Dubai'],
        ['code' => 'SHJ', 'name_ar' => 'الشارقة', 'name_en' => 'Sharjah'],
        ['code' => 'AJM', 'name_ar' => 'عجمان', 'name_en' => 'Ajman'],
        ['code' => 'UAQ', 'name_ar' => 'أم القيوين', 'name_en' => 'Umm Al Quwain'],
        ['code' => 'RAK', 'name_ar' => 'رأس الخيمة', 'name_en' => 'Ras Al Khaimah'],
        ['code' => 'FUJ', 'name_ar' => 'الفجيرة', 'name_en' => 'Fujairah'],
    ];

    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_ar', 100);
            $table->string('name_en', 100)->nullable();
            $table->string('country', 80)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::table('regions')->insert(array_map(fn (array $emirate, int $i) => [
            ...$emirate,
            'country' => 'AE',
            'sort_order' => $i + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], self::EMIRATES, array_keys(self::EMIRATES)));

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('country')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
        });

        Schema::dropIfExists('regions');
    }
};
