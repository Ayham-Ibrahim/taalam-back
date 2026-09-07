<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فيديو تعريفي واحد (intro_youtube_id على المعلم نفسه، عبر مسار تحديث الملف
 * الشخصي الموجود أصلاً) + قائمة فيديوهات إضافية (جدول teacher_videos، مسار
 * مستقل add/remove) — كلاهما رابط يوتيوب فقط، يُخزَّن معرّف الفيديو النظيف
 * (11 حرفاً) بصرف النظر عن صيغة الرابط المُدخَلة.
 */
class TeacherVideosTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_set_their_own_intro_video_from_any_known_youtube_url_format(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", [
            'bio' => 'نبذة',
            'intro_youtube_id' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'intro_youtube_id' => 'dQw4w9WgXcQ']);
    }

    public static function youtubeUrlFormatProvider(): array
    {
        return [
            'youtu.be short link' => ['https://youtu.be/dQw4w9WgXcQ'],
            'shorts link' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ'],
            'embed link' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'bare video id' => ['dQw4w9WgXcQ'],
        ];
    }

    /** @dataProvider youtubeUrlFormatProvider */
    public function test_every_known_youtube_url_format_extracts_the_same_clean_id(string $input): void
    {
        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['intro_youtube_id' => $input]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'intro_youtube_id' => 'dQw4w9WgXcQ']);
    }

    public function test_an_invalid_intro_video_value_is_rejected(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", [
            'intro_youtube_id' => 'https://vimeo.com/12345',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('intro_youtube_id');
    }

    public function test_teacher_can_add_and_remove_additional_videos(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $add = $this->as($token)->postJson("/api/teachers/{$teacher->id}/videos", [
            'youtube_id' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw',
            'title' => 'كيف أحل معادلة تفاضلية',
        ]);

        $add->assertStatus(201)->assertJsonPath('data.youtube_id', 'jNQXAC9IVRw');
        $this->assertDatabaseHas('teacher_videos', ['teacher_id' => $teacher->id, 'youtube_id' => 'jNQXAC9IVRw']);
        $videoId = $add->json('data.id');

        $remove = $this->as($token)->deleteJson("/api/teacher-videos/{$videoId}");
        $remove->assertStatus(200);
        $this->assertDatabaseMissing('teacher_videos', ['id' => $videoId]);
    }

    public function test_cannot_add_more_than_the_maximum_allowed_videos(): void
    {
        [$teacher, $token] = $this->createTeacher();

        for ($i = 0; $i < Teacher::MAX_VIDEOS; $i++) {
            $this->as($token)->postJson("/api/teachers/{$teacher->id}/videos", [
                'youtube_id' => str_pad((string) $i, 11, 'a'),
            ])->assertStatus(201);
        }

        $overflow = $this->as($token)->postJson("/api/teachers/{$teacher->id}/videos", [
            'youtube_id' => str_pad('x', 11, 'a'),
        ]);

        $overflow->assertStatus(422)->assertJsonValidationErrors('videos');
        $this->assertSame(Teacher::MAX_VIDEOS, $teacher->videos()->count());
    }

    /** لا يمكن لمعلم آخر (ليس صاحب الحساب ولا أدمن) إضافة/حذف فيديو لمعلم غيره */
    public function test_a_different_teacher_cannot_manage_another_teachers_videos(): void
    {
        [$teacher] = $this->createTeacher();
        [, $intruderToken] = $this->createTeacher();

        $add = $this->as($intruderToken)->postJson("/api/teachers/{$teacher->id}/videos", [
            'youtube_id' => 'dQw4w9WgXcQ',
        ]);
        $add->assertStatus(403);

        $video = $teacher->videos()->create(['youtube_id' => 'dQw4w9WgXcQ', 'sort_order' => 0]);
        $remove = $this->as($intruderToken)->deleteJson("/api/teacher-videos/{$video->id}");
        $remove->assertStatus(403);
    }

    /** الأدمن يدير فيديوهات أي معلم — نفس نمط بقية توسيعات "الإكمال نيابة عن المعلم" */
    public function test_admin_can_manage_a_teachers_videos_and_intro_video_on_their_behalf(): void
    {
        [$teacher] = $this->createTeacher();
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $updateIntro = $this->as($adminToken)->putJson("/api/teachers/{$teacher->id}", [
            'intro_youtube_id' => 'dQw4w9WgXcQ',
        ]);
        $updateIntro->assertStatus(200);

        $add = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/videos", ['youtube_id' => 'jNQXAC9IVRw']);
        $add->assertStatus(201);

        $remove = $this->as($adminToken)->deleteJson("/api/teacher-videos/{$add->json('data.id')}");
        $remove->assertStatus(200);
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createTeacher(): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school']);

        return [$teacher, $user->createToken('t')->plainTextToken];
    }
}
