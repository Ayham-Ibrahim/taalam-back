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

        $response->assertStatus(422)
            ->assertJsonPath('errors.avatar.0', 'أبعاد الصورة صغيرة جداً — الحد الأدنى 400×400 بكسل');
    }

    /**
     * لا يوجد lang/ar في هذا المشروع (APP_LOCALE=en) — بلا messages() عربية
     * صريحة كانت هذه الرسالة تصل بالإنجليزية الافتراضية من Laravel.
     */
    public function test_avatar_over_5mb_is_rejected_with_an_arabic_message(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->create();
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 500, 500)->size(5121),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.avatar.0', 'حجم الصورة أكبر من الحد المسموح (5 ميغابايت كحد أقصى)');
        $this->assertNull($user->fresh()->avatar_path);
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

    /** حذف الصورة يعيد الحساب للصورة الافتراضية (أحرف الاسم الأولى في الواجهة) ويمسح الملف الفعلي من القرص */
    public function test_user_can_delete_their_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->student()->create();
        $token = $user->createToken('t')->plainTextToken;

        $upload = $this->as($token)->post('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 500, 500),
        ]);
        $path = str_replace(Storage::disk('public')->url(''), '', $upload->json('data.avatar_path'));
        Storage::disk('public')->assertExists($path);

        $response = $this->as($token)->deleteJson('/api/me/avatar');

        $response->assertStatus(200)->assertJsonPath('data.avatar_path', null);
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    /** عملية آمنة التكرار — لا خطأ إن لم تكن هناك صورة أصلاً */
    public function test_deleting_avatar_when_none_exists_is_a_no_op(): void
    {
        $user = User::factory()->student()->create();
        $token = $user->createToken('t')->plainTextToken;

        $response = $this->as($token)->deleteJson('/api/me/avatar');

        $response->assertStatus(200)->assertJsonPath('data.avatar_path', null);
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
