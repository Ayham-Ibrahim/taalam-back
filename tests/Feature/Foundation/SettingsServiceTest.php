<?php

namespace Tests\Feature\Foundation;

use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_casts_values_by_declared_type(): void
    {
        $this->seed(SettingsSeeder::class);
        $settings = app(SettingsService::class);

        $this->assertSame(60.0, $settings->get('default_platform_margin_percent'));
        $this->assertIsFloat($settings->get('default_platform_margin_percent'));

        $this->assertSame(6, $settings->get('advance_notice_hours'));
        $this->assertIsInt($settings->get('advance_notice_hours'));

        $this->assertTrue($settings->get('reschedule_requires_admin'));
    }

    public function test_set_updates_value_invalidates_cache_and_logs_audit(): void
    {
        $this->seed(SettingsSeeder::class);
        $settings = app(SettingsService::class);

        $this->assertSame(60.0, $settings->get('default_platform_margin_percent'));

        $settings->set('default_platform_margin_percent', '55');

        $this->assertSame(55.0, $settings->get('default_platform_margin_percent'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_set_rejects_non_editable_setting(): void
    {
        $this->seed(SettingsSeeder::class);
        $settings = app(SettingsService::class);

        $this->expectException(RuntimeException::class);

        $settings->set('reschedule_requires_admin', '0');
    }
}
