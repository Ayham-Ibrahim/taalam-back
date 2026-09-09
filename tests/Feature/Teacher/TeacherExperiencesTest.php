<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خبرات سابقة يديرها المعلم بنفسه (أو الأدمن نيابة عنه) — تظهر في بروفايله
 * العام. يوازي TeacherFaqsTest تماماً في البنية والصلاحيات.
 */
class TeacherExperiencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_add_and_remove_their_own_experiences(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $add = $this->as($token)->postJson("/api/teachers/{$teacher->id}/experiences", [
            'title' => 'مدرس في مدارس خاصة بدبي',
            'period' => '2019 - 2022',
        ]);

        $add->assertStatus(201)->assertJsonPath('data.title', 'مدرس في مدارس خاصة بدبي');
        $this->assertDatabaseHas('teacher_experiences', ['teacher_id' => $teacher->id, 'title' => 'مدرس في مدارس خاصة بدبي']);
        $experienceId = $add->json('data.id');

        $remove = $this->as($token)->deleteJson("/api/teacher-experiences/{$experienceId}");
        $remove->assertStatus(200);
        $this->assertDatabaseMissing('teacher_experiences', ['id' => $experienceId]);
    }

    public function test_experiences_appear_on_the_public_profile_of_a_verified_teacher(): void
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $token = $user->createToken('t')->plainTextToken;

        $this->as($token)->postJson("/api/teachers/{$teacher->id}/experiences", [
            'title' => 'مدرس في أكاديمية تعليمية',
            'period' => '2022 - الآن',
        ])->assertStatus(201);

        $response = $this->getJson("/api/teachers/{$teacher->id}");
        $response->assertStatus(200)
            ->assertJsonPath('data.experiences.0.title', 'مدرس في أكاديمية تعليمية')
            ->assertJsonPath('data.experiences.0.period', '2022 - الآن');
    }

    public function test_cannot_add_more_than_the_maximum_allowed_experiences(): void
    {
        [$teacher, $token] = $this->createTeacher();

        for ($i = 0; $i < Teacher::MAX_EXPERIENCES; $i++) {
            $this->as($token)->postJson("/api/teachers/{$teacher->id}/experiences", [
                'title' => "خبرة رقم {$i}",
                'period' => '2020 - 2021',
            ])->assertStatus(201);
        }

        $overflow = $this->as($token)->postJson("/api/teachers/{$teacher->id}/experiences", [
            'title' => 'خبرة زائدة',
            'period' => '2021 - 2022',
        ]);

        $overflow->assertStatus(422)->assertJsonValidationErrors('experiences');
        $this->assertSame(Teacher::MAX_EXPERIENCES, $teacher->experiences()->count());
    }

    /** لا يمكن لمعلم آخر (ليس صاحب الحساب ولا أدمن) إضافة/حذف خبرة لمعلم غيره */
    public function test_a_different_teacher_cannot_manage_another_teachers_experiences(): void
    {
        [$teacher] = $this->createTeacher();
        [, $intruderToken] = $this->createTeacher();

        $add = $this->as($intruderToken)->postJson("/api/teachers/{$teacher->id}/experiences", [
            'title' => 'خبرة',
            'period' => '2020 - 2021',
        ]);
        $add->assertStatus(403);

        $experience = $teacher->experiences()->create(['title' => 'خبرة', 'period' => '2020 - 2021', 'sort_order' => 0]);
        $remove = $this->as($intruderToken)->deleteJson("/api/teacher-experiences/{$experience->id}");
        $remove->assertStatus(403);
    }

    /** الأدمن يدير خبرات أي معلم — نفس نمط بقية توسيعات "الإكمال نيابة عن المعلم" */
    public function test_admin_can_manage_a_teachers_experiences_on_their_behalf(): void
    {
        [$teacher] = $this->createTeacher();
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $add = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/experiences", [
            'title' => 'خبرة أضافها الأدمن',
            'period' => '2018 - 2020',
        ]);
        $add->assertStatus(201);

        $remove = $this->as($adminToken)->deleteJson("/api/teacher-experiences/{$add->json('data.id')}");
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
