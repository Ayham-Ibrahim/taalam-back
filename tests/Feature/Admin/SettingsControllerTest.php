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

        $update = $this->as($token)->putJson('/api/admin/settings/platform_margin_percent_default', ['value' => 65]);
        $update->assertStatus(200);
        $this->assertEquals(65, $update->json('data.value'));
    }

    public function test_non_admin_cannot_view_settings(): void
    {
        $teacherUser = User::factory()->teacher()->create();
        $token = $teacherUser->createToken('t')->plainTextToken;

        $response = $this->as($token)->getJson('/api/admin/settings');

        $response->assertStatus(403);
    }
}
