<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الأدمن يرفع/يحذف صورة معلم نيابة عنه — يعيد استخدام AuthService::updateAvatar/
 * deleteAvatar نفسه (يقبل $user منذ البداية، لم يكن مقيَّداً بالمستخدم الحالي)،
 * فقط عبر مسار جديد يستهدف مستخدم المعلم صراحة بدل $request->user().
 */
class AdminManagesTeacherAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_and_delete_a_teachers_avatar_on_their_behalf(): void
    {
        Storage::fake('public');

        [$teacher, , $adminToken] = $this->createTeacherAndAdmin();

        $upload = $this->as($adminToken)->post("/api/teachers/{$teacher->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('photo.jpg', 500, 500),
        ]);

        $upload->assertStatus(200);
        $teacher->refresh()->loadMissing('user');
        $this->assertNotNull($teacher->user->avatar_path);

        $delete = $this->as($adminToken)->deleteJson("/api/teachers/{$teacher->id}/avatar");
        $delete->assertStatus(200);
        $this->assertNull($teacher->user->fresh()->avatar_path);
    }

    public function test_a_different_teacher_cannot_upload_another_teachers_avatar(): void
    {
        Storage::fake('public');

        [$teacher] = $this->createTeacherAndAdmin();
        $intruderToken = User::factory()->teacher()->create()->createToken('t')->plainTextToken;

        $upload = $this->as($intruderToken)->post("/api/teachers/{$teacher->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('photo.jpg', 500, 500),
        ]);

        $upload->assertStatus(403);
    }

    /**
     * @return array{0: Teacher, 1: string, 2: string}
     */
    private function createTeacherAndAdmin(): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school']);
        $admin = User::factory()->admin()->create();

        return [$teacher, $user->createToken('t')->plainTextToken, $admin->createToken('t')->plainTextToken];
    }
}
