<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_update_an_editable_setting(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        Setting::create([
            'key' => 'platform_margin_percent_default',
            'value' => '60',
            'type' => 'integer',
            'group' => 'pricing',
            'label_ar' => 'هامش الربح الافتراضي',
            'is_editable' => true,
        ]);

        $list = $this->as($token)->getJson('/api/admin/settings');
        $list->assertStatus(200);
        $this->assertEquals(60, $list->json('data.0.value'));
        $this->assertSame('integer', $list->json('data.0.type'));

        $update = $this->as($token)->putJson('/api/admin/settings/platform_margin_percent_default', ['value' => 65]);
        $update->assertStatus(200);
        $this->assertEquals(65, $update->json('data.value'));
        $this->assertSame('integer', $update->json('data.type'));
    }

    public function test_settings_of_every_type_expose_their_type_and_correctly_cast_value(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        Setting::create([
            'key' => 'default_currency',
            'value' => 'USD',
            'type' => 'string',
            'group' => 'pricing',
            'label_ar' => 'العملة الافتراضية',
            'is_editable' => true,
        ]);
        Setting::create([
            'key' => 'reschedule_requires_admin',
            'value' => '1',
            'type' => 'boolean',
            'group' => 'booking',
            'label_ar' => 'يتطلب موافقة الأدمن',
            'is_editable' => false,
        ]);

        $list = $this->as($token)->getJson('/api/admin/settings');
        $list->assertStatus(200);

        $byKey = collect($list->json('data'))->keyBy('key');
        $this->assertSame('string', $byKey['default_currency']['type']);
        $this->assertSame('USD', $byKey['default_currency']['value']);
        $this->assertSame('boolean', $byKey['reschedule_requires_admin']['type']);
        $this->assertTrue($byKey['reschedule_requires_admin']['value']);
    }

    public function test_non_admin_cannot_view_settings(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson('/api/admin/settings');

        $response->assertStatus(403);
    }
}
