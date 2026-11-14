<?php

namespace App\Support;

use App\Models\Country;
use App\Models\Region;
use Illuminate\Support\Collection;

/**
 * يحوّل نصاً حراً ("دبى"، "Abu Dhabi"، "العين"، "UAE") إلى ولاية/دولة من الجداول —
 * لمطابقة students.city/country القديمين واستيراد Excel. يقارن بالرمز والاسمين بعد
 * توحيد الكتابة (همزات، ى/ي، ة/ه، "ال"، المسافات)، ثم بمرادفات شائعة.
 */
class RegionMatcher
{
    /** مدن وكتابات شائعة لا تطابق اسم الإمارة نفسه — رمز الدولة => رمز الولاية => مرادفات */
    private const REGION_ALIASES = [
        'AE' => [
            'AUH' => ['abudhabi', 'abuzabi', 'ابوظبي', 'ابوظبى', 'عين', 'alain', 'ain', 'مدينهزايد', 'رويس', 'ruwais'],
            'DXB' => ['dubai', 'dubay', 'دبي', 'جبلعلي', 'jebelali', 'حتا', 'hatta'],
            'SHJ' => ['sharjah', 'sharja', 'شارقه', 'خورفكان', 'khorfakkan', 'كلباء', 'kalba', 'ذيد', 'dhaid'],
            'AJM' => ['ajman', 'عجمان'],
            'UAQ' => ['ummalquwain', 'ummalqaiwain', 'uaq', 'امالقيوين', 'امقيوين'],
            'RAK' => ['rasalkhaimah', 'rasalkhaima', 'rak', 'راسالخيمه', 'راسخيمه'],
            'FUJ' => ['fujairah', 'fujeirah', 'فجيره', 'دبا', 'dibba'],
        ],
    ];

    private const COUNTRY_ALIASES = [
        'AE' => ['uae', 'emirates', 'unitedarabemirates', 'امارات', 'اماراتالعربيهالمتحده'],
        'SA' => ['ksa', 'saudi', 'saudiarabia', 'سعوديه', 'مملكهالعربيهالسعوديه'],
        'OM' => ['oman', 'عمان', 'سلطنهعمان'],
    ];

    /** @var Collection<string, int>|null مفتاح موحَّد => region_id */
    private ?Collection $regionIndex = null;

    /** @var Collection<string, int>|null مفتاح موحَّد => country_id */
    private ?Collection $countryIndex = null;

    public function match(?string $value): ?int
    {
        $key = self::normalize($value);

        return $key === '' ? null : $this->regionIndex()->get($key);
    }

    public function matchCountry(?string $value): ?int
    {
        $key = self::normalize($value);

        return $key === '' ? null : $this->countryIndex()->get($key);
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

    private function regionIndex(): Collection
    {
        return $this->regionIndex ??= Region::query()->with('country:id,code')->get(['id', 'country_id', 'code', 'name_ar', 'name_en'])
            ->flatMap(function (Region $region) {
                $aliases = self::REGION_ALIASES[strtoupper($region->country?->code ?? '')][strtoupper($region->code)] ?? [];

                return $this->keysFor([$region->code, $region->name_ar, $region->name_en, ...$aliases], $region->id);
            });
    }

    private function countryIndex(): Collection
    {
        return $this->countryIndex ??= Country::query()->get(['id', 'code', 'name_ar', 'name_en'])
            ->flatMap(fn (Country $country) => $this->keysFor(
                [$country->code, $country->name_ar, $country->name_en, ...(self::COUNTRY_ALIASES[strtoupper($country->code)] ?? [])],
                $country->id,
            ));
    }

    private function keysFor(array $values, int $id): Collection
    {
        return collect($values)->filter()->mapWithKeys(fn (string $v) => [self::normalize($v) => $id]);
    }
}
