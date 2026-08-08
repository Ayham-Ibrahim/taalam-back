<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_an_avatar_with_good_resolution(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->create();
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 500, 500),
        ]);

        $response->assertStatus(200);
        $url = $response->json('data.avatar_path');
        $this->assertNotNull($url);
        $this->assertStringContainsString('/storage/avatars/', $url);
    }

    public function test_avatar_below_minimum_resolution_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->create();
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 100, 100),
        ]);

        $response->assertStatus(422);
    }

    public function test_uploading_a_new_avatar_replaces_and_deletes_the_old_one(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->create();
        $token = $user->createToken('t')->plainTextToken;

        $first = $this->as($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 500, 500),
        ]);
        $firstPath = str_replace(Storage::disk('public')->url(''), '', $first->json('data.avatar_path'));
        Storage::disk('public')->assertExists($firstPath);

        $second = $this->as($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg', 500, 500),
        ]);
        $second->assertStatus(200);

        Storage::disk('public')->assertMissing($firstPath);
    }

    /** 15 رفعة/دقيقة لكل مستخدم (throttle:uploads) — يمنع استنزاف مساحة التخزين */
    public function test_avatar_uploads_are_rate_limited(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->create();
        $token = $user->createToken('t')->plainTextToken;

        for ($i = 0; $i < 15; $i++) {
            $this->as($token)->post('/api/me/avatar', [
                'avatar' => UploadedFile::fake()->image("avatar{$i}.jpg", 500, 500),
            ])->assertStatus(200);
        }

        $this->as($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar16.jpg', 500, 500),
        ])->assertStatus(429);
    }
}
