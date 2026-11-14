<?php

namespace App\Support;

use App\Models\Region;
use Illuminate\Support\Collection;

/**
 * يحوّل نصاً حراً ("دبى"، "Abu Dhabi"، "العين") إلى ولاية من جدول regions —
 * لمطابقة students.city القديم واستيراد Excel. يقارن بالرمز والاسمين بعد توحيد
 * الكتابة (همزات، ى/ي، ة/ه، "ال"، المسافات)، ثم بمرادفات لمدن داخل كل إمارة.
 */
class RegionMatcher
{
    /** مدن وكتابات شائعة لا تطابق اسم الإمارة نفسه — رمز الإمارة => مرادفات */
    private const ALIASES = [
        'AUH' => ['abudhabi', 'abuzabi', 'ابوظبي', 'ابوظبى', 'عين', 'alain', 'ain', 'مدينهزايد', 'رويس', 'ruwais'],
        'DXB' => ['dubai', 'dubay', 'دبي', 'جبلعلي', 'jebelali', 'حتا', 'hatta'],
        'SHJ' => ['sharjah', 'sharja', 'شارقه', 'خورفكان', 'khorfakkan', 'كلباء', 'kalba', 'ذيد', 'dhaid'],
        'AJM' => ['ajman', 'عجمان'],
        'UAQ' => ['ummalquwain', 'ummalqaiwain', 'uaq', 'امالقيوين', 'امقيوين'],
        'RAK' => ['rasalkhaimah', 'rasalkhaima', 'rak', 'راسالخيمه', 'راسخيمه'],
        'FUJ' => ['fujairah', 'fujeirah', 'فجيره', 'دبا', 'dibba'],
    ];

    /** @var Collection<string, int>|null مفتاح موحَّد => region_id */
    private ?Collection $index = null;

    public function match(?string $value): ?int
    {
        $key = self::normalize($value);

        return $key === '' ? null : $this->index()->get($key);
    }

    public static function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ـ' => '']);
        $value = preg_replace('/[\p{Mn}]/u', '', $value); // التشكيل
        $value = preg_replace('/^(emirate of|إمارة|امارة)\s*/u', '', $value);
        $value = preg_replace('/^ال/u', '', $value);

        return preg_replace('/[^\p{L}\p{N}]/u', '', $value);
    }

    private function index(): Collection
    {
        return $this->index ??= Region::query()->get(['id', 'code', 'name_ar', 'name_en'])
            ->flatMap(function (Region $region) {
                $keys = [$region->code, $region->name_ar, $region->name_en, ...(self::ALIASES[strtoupper($region->code)] ?? [])];

                return collect($keys)->filter()->mapWithKeys(fn (string $k) => [self::normalize($k) => $region->id]);
            });
    }
}
