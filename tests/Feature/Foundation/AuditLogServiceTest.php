<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_records_actor_ip_and_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $log = app(AuditLogService::class)->log(
            'package.approved',
            $admin,
            ['status' => 'pending_approval'],
            ['status' => 'active'],
            'موافقة تجريبية',
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'package.approved',
            'user_id' => $admin->id,
            'model_type' => User::class,
            'model_id' => $admin->id,
            'notes' => 'موافقة تجريبية',
        ]);

        $this->assertSame(['status' => 'pending_approval'], $log->fresh()->old_values);
        $this->assertSame(['status' => 'active'], $log->fresh()->new_values);
    }
}
