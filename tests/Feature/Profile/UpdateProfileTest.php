<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_their_own_basic_profile(): void
    {
        $user = User::factory()->student()->create(['name' => 'قديم', 'phone' => null]);
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->putJson('/api/me/profile', [
            'name' => 'اسم جديد',
            'phone' => '0599999999',
            'whatsapp' => '0599999999',
            'gender' => 'male',
        ]);

        $response->assertStatus(200);
        $this->assertSame('اسم جديد', $response->json('data.name'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'اسم جديد', 'phone' => '0599999999']);
    }

    public function test_timezone_sync_updates_when_auto_detect_is_enabled(): void
    {
        $user = User::factory()->student()->create(['timezone' => 'UTC', 'timezone_auto' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->putJson('/api/me/timezone/sync', ['timezone' => 'Asia/Tokyo']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Asia/Tokyo']);
    }

    public function test_timezone_sync_is_a_silent_no_op_once_the_user_set_it_manually(): void
    {
        $user = User::factory()->student()->create(['timezone' => 'Asia/Riyadh', 'timezone_auto' => false]);
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->putJson('/api/me/timezone/sync', ['timezone' => 'Asia/Tokyo']);

        // لا يفشل الطلب — لكن لا يُطبَّق أي تحديث فوق اختيار المستخدم اليدوي
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Asia/Riyadh']);
    }

    public function test_manual_profile_update_with_timezone_disables_future_auto_sync(): void
    {
        $user = User::factory()->student()->create(['timezone' => 'UTC', 'timezone_auto' => true]);
        $token = $user->createToken('t')->plainTextToken;

        $this->as($token)->putJson('/api/me/profile', [
            'name' => $user->name,
            'timezone' => 'Europe/London',
            'timezone_auto' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Europe/London', 'timezone_auto' => false]);

        // مزامنة تلقائية لاحقة لا يجب أن تطغى على هذا الاختيار اليدوي
        $this->as($token)->putJson('/api/me/timezone/sync', ['timezone' => 'Asia/Tokyo'])->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Europe/London']);
    }

    public function test_re_enabling_auto_detect_allows_future_sync_to_update_again(): void
    {
        $user = User::factory()->student()->create(['timezone' => 'Europe/London', 'timezone_auto' => false]);
        $token = $user->createToken('t')->plainTextToken;

        $this->as($token)->putJson('/api/me/profile', [
            'name' => $user->name,
            'timezone_auto' => true,
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone_auto' => true]);

        $this->as($token)->putJson('/api/me/timezone/sync', ['timezone' => 'Asia/Tokyo'])->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'timezone' => 'Asia/Tokyo']);
    }

    public function test_timezone_sync_rejects_an_invalid_timezone_identifier(): void
    {
        $user = User::factory()->student()->create();
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->putJson('/api/me/timezone/sync', ['timezone' => 'Not/A_Real_Zone']);

        $response->assertStatus(422)->assertJsonValidationErrors(['timezone']);
    }

    public function test_profile_update_rejects_a_phone_already_used_by_another_user(): void
    {
        User::factory()->student()->create(['phone' => '0511111111']);
        $user = User::factory()->student()->create(['phone' => null]);
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->putJson('/api/me/profile', [
            'name' => $user->name,
            'phone' => '0511111111',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_user_can_change_their_password_with_correct_current_password(): void
    {
        $user = User::factory()->student()->create(['password' => 'OldPass123']);
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->putJson('/api/me/password', [
            'current_password' => 'OldPass123',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ]);

        $response->assertStatus(200);

        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'NewPass456']);
        $login->assertStatus(200);
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $user = User::factory()->student()->create(['password' => 'OldPass123']);
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->putJson('/api/me/password', [
            'current_password' => 'WrongPass1',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    }

    public function test_password_change_revokes_other_sessions_but_keeps_the_current_one(): void
    {
        $user = User::factory()->student()->create(['password' => 'OldPass123']);
        $currentToken = $user->createToken('current')->plainTextToken;
        $otherToken = $user->createToken('other')->plainTextToken;

        $this->as($currentToken)->putJson('/api/me/password', [
            'current_password' => 'OldPass123',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ])->assertStatus(200);

        $this->as($currentToken)->getJson('/api/auth/me')->assertStatus(200);
        $this->as($otherToken)->getJson('/api/auth/me')->assertStatus(401);
    }
}
