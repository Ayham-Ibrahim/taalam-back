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
