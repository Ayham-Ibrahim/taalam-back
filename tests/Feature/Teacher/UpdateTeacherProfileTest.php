<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTeacherProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_bio_up_to_500_characters_is_accepted(): void
    {
        [$teacher, $token] = $this->createTeacher();
        $bio = str_repeat('أ', 500);

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['bio' => $bio]);

        $response->assertStatus(200)->assertJsonPath('data.bio', $bio);
    }

    public function test_bio_over_500_characters_is_rejected(): void
    {
        [$teacher, $token] = $this->createTeacher();
        $bio = str_repeat('أ', 501);

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['bio' => $bio]);

        $response->assertStatus(422)->assertJsonValidationErrors('bio');
    }

    /**
     * فاليديشن صارم على مستوى الباك اند أيضاً — أحرف فقط بلا أرقام لاسم العرض
     * بالإنجليزية، بنفس قاعدة حقل "الاسم" في نافذة إضافة معلم عند الأدمن.
     */
    public function test_display_name_en_with_digits_is_rejected(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['display_name_en' => 'Center123']);

        $response->assertStatus(422)->assertJsonValidationErrors('display_name_en');
    }

    public function test_valid_display_name_en_is_accepted_for_a_training_center(): void
    {
        [, $token] = $this->createTeacher();
        $centerUser = User::factory()->teacher()->create();
        $center = Teacher::create(['user_id' => $centerUser->id, 'teacher_type' => 'training_center']);
        $centerToken = $centerUser->createToken('t')->plainTextToken;

        $response = $this->as($centerToken)->putJson("/api/teachers/{$center->id}", [
            'display_name_en' => 'Bright Future Academy',
            'commercial_register' => 'CR-12345',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.display_name_en', 'Bright Future Academy');
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
