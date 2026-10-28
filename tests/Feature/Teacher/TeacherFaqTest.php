<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أسئلة شائعة يكتبها المعلم بنفسه من لوحته (جدول teacher_faqs، مسار مستقل
 * add/update/remove) وتظهر في بروفايله العام — نفس نمط teacher_videos.
 */
class TeacherFaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_add_update_and_remove_their_own_faqs(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $add = $this->as($token)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'هل الحصة التجريبية مجانية فعلاً؟',
            'answer' => 'نعم، الحصة الأولى مجانية بالكامل.',
        ]);

        $add->assertStatus(201)->assertJsonPath('data.question', 'هل الحصة التجريبية مجانية فعلاً؟');
        $this->assertDatabaseHas('teacher_faqs', ['teacher_id' => $teacher->id, 'question' => 'هل الحصة التجريبية مجانية فعلاً؟']);
        $faqId = $add->json('data.id');

        $update = $this->as($token)->putJson("/api/teacher-faqs/{$faqId}", [
            'question' => 'هل الحصة التجريبية مجانية؟',
            'answer' => 'نعم، أول 20 دقيقة مجانية.',
        ]);
        $update->assertStatus(200)->assertJsonPath('data.answer', 'نعم، أول 20 دقيقة مجانية.');

        $remove = $this->as($token)->deleteJson("/api/teacher-faqs/{$faqId}");
        $remove->assertStatus(200);
        $this->assertDatabaseMissing('teacher_faqs', ['id' => $faqId]);
    }

    public function test_a_faq_requires_both_a_question_and_an_answer(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $this->as($token)->postJson("/api/teachers/{$teacher->id}/faqs", ['question' => 'سؤال بلا جواب'])
            ->assertStatus(422)->assertJsonValidationErrors('answer');
    }

    public function test_cannot_add_more_than_the_maximum_allowed_faqs(): void
    {
        [$teacher, $token] = $this->createTeacher();

        for ($i = 0; $i < Teacher::MAX_FAQS; $i++) {
            $this->as($token)->postJson("/api/teachers/{$teacher->id}/faqs", [
                'question' => "سؤال {$i}",
                'answer' => "جواب {$i}",
            ])->assertStatus(201);
        }

        $overflow = $this->as($token)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'سؤال زائد',
            'answer' => 'جواب زائد',
        ]);

        $overflow->assertStatus(422)->assertJsonValidationErrors('faqs');
        $this->assertSame(Teacher::MAX_FAQS, $teacher->faqs()->count());
    }

    public function test_a_different_teacher_cannot_manage_another_teachers_faqs(): void
    {
        [$teacher] = $this->createTeacher();
        [, $intruderToken] = $this->createTeacher();

        $this->as($intruderToken)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'س', 'answer' => 'ج',
        ])->assertStatus(403);

        $faq = $teacher->faqs()->create(['question' => 'س', 'answer' => 'ج', 'sort_order' => 0]);
        $this->as($intruderToken)->deleteJson("/api/teacher-faqs/{$faq->id}")->assertStatus(403);
        $this->as($intruderToken)->putJson("/api/teacher-faqs/{$faq->id}", ['question' => 'x', 'answer' => 'y'])->assertStatus(403);
    }

    public function test_admin_can_manage_a_teachers_faqs_on_their_behalf(): void
    {
        [$teacher] = $this->createTeacher();
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;

        $add = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'س', 'answer' => 'ج',
        ]);
        $add->assertStatus(201);

        $this->as($adminToken)->deleteJson("/api/teacher-faqs/{$add->json('data.id')}")->assertStatus(200);
    }

    public function test_faqs_appear_on_the_public_profile(): void
    {
        [$teacher] = $this->createTeacher(['status' => 'verified']);
        $teacher->faqs()->create(['question' => 'كيف أحجز؟', 'answer' => 'من زر احجز الآن.', 'sort_order' => 0]);

        $response = $this->getJson("/api/teachers/{$teacher->id}");

        $response->assertStatus(200)->assertJsonPath('data.faqs.0.question', 'كيف أحجز؟');
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createTeacher(array $overrides = []): array
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(array_merge(['user_id' => $user->id, 'teacher_type' => 'school'], $overrides));

        return [$teacher, $user->createToken('t')->plainTextToken];
    }
}
