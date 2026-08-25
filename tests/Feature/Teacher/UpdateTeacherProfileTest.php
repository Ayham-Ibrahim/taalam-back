<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTeacherProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_bio_up_to_1000_characters_is_accepted(): void
    {
        [$teacher, $token] = $this->createTeacher();
        $bio = str_repeat('أ', 1000);

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['bio' => $bio]);

        $response->assertStatus(200)->assertJsonPath('data.bio', $bio);
    }

    public function test_bio_over_1000_characters_is_rejected(): void
    {
        [$teacher, $token] = $this->createTeacher();
        $bio = str_repeat('أ', 1001);

        $response = $this->as($token)->putJson("/api/teachers/{$teacher->id}", ['bio' => $bio]);

        $response->assertStatus(422)->assertJsonValidationErrors('bio');
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
