<?php

namespace App\Services;

use App\Models\Setting;
use App\Traits\LogsAuditEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * البوابة الوحيدة لقراءة/تعديل إعدادات النظام. لا يُقرأ جدول settings
 * مباشرة من أي مكان آخر — هذا ما يضمن أن قيماً مثل نسبة المنصة الافتراضية
 * أو مهلة الإشعار المسبق لا تُكتب hardcoded داخل الكود.
 */
class SettingsService
{
    use LogsAuditEvents;

    private const CACHE_KEY = 'settings:all';

    private const CACHE_TTL = 3600;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()->get($key, $default);
    }

    public function all(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Setting::all()->mapWithKeys(
                fn (Setting $setting) => [$setting->key => $setting->castValue()]
            );
        });
    }

    public function set(string $key, mixed $value): Setting
    {
        $setting = Setting::where('key', $key)->firstOrFail();

        if (! $setting->is_editable) {
            throw new RuntimeException("الإعداد \"{$key}\" غير قابل للتعديل");
        }

        $oldValue = $setting->value;
        $setting->update(['value' => (string) $value]);

        Cache::forget(self::CACHE_KEY);

        $this->audit(
            'settings.updated',
            $setting,
            ['value' => $oldValue],
            ['value' => $setting->value],
        );

        return $setting;
    }
}
