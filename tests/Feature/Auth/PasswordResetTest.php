<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_reset_link_for_an_existing_account(): void
    {
        Notification::fake();

        $user = User::factory()->student()->create(['email' => 'forgot@example.com']);

        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'forgot@example.com']);

        $response->assertStatus(200);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    /** لا يجب أن يكشف الطلب وجود/عدم وجود الحساب — نفس الرسالة والحالة تماماً في الحالتين */
    public function test_forgot_password_returns_the_same_generic_response_for_an_unknown_email(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'forgot2@example.com']);
        User::factory()->student()->create(['email' => 'forgot2@example.com']);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'does.not.exist@example.com']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_a_user_can_reset_their_password_with_a_valid_token_and_then_log_in_with_it(): void
    {
        $user = User::factory()->student()->create(['email' => 'reset@example.com', 'password' => 'OldPassword123!']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(200);

        $login = $this->postJson('/api/auth/login', ['email' => 'reset@example.com', 'password' => 'NewPassword123!']);
        $login->assertStatus(200);

        $oldLogin = $this->postJson('/api/auth/login', ['email' => 'reset@example.com', 'password' => 'OldPassword123!']);
        $oldLogin->assertStatus(422);
    }

    /** إعادة التعيين تُبطل كل جلسة قائمة مسبقاً — دفاع في العمق، نفس منطق AuthService::updatePassword */
    public function test_resetting_the_password_revokes_all_existing_tokens(): void
    {
        $user = User::factory()->student()->create(['email' => 'revoke@example.com', 'password' => 'OldPassword123!']);
        $existingToken = $user->createToken('old-session')->plainTextToken;
        $this->assertSame(1, $user->tokens()->count());

        $token = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', [
            'email' => 'revoke@example.com',
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertStatus(200);

        $this->assertSame(0, $user->tokens()->count());

        $meWithOldToken = $this->as($existingToken)->getJson('/api/auth/me');
        $meWithOldToken->assertStatus(401);
    }

    public function test_reset_password_rejects_an_invalid_token(): void
    {
        User::factory()->student()->create(['email' => 'badtoken@example.com']);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'badtoken@example.com',
            'token' => 'not-a-real-token',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(422);
    }

    public function test_reset_password_requires_password_confirmation_to_match(): void
    {
        $user = User::factory()->student()->create(['email' => 'mismatch@example.com']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'mismatch@example.com',
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'SomethingElse123!',
        ]);

        $response->assertStatus(422);
    }
}
