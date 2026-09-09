<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أسئلة شائعة يديرها المعلم بنفسه (أو الأدمن نيابة عنه) — تظهر في بروفايله
 * العام. يوازي TeacherVideosTest تماماً في البنية والصلاحيات.
 */
class TeacherFaqsTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_add_and_remove_their_own_faqs(): void
    {
        [$teacher, $token] = $this->createTeacher();

        $add = $this->as($token)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'هل الحصة التجريبية مجانية؟',
            'answer' => 'لا، لكن يمكنك التواصل معي لمناقشة التفاصيل قبل الحجز.',
        ]);

        $add->assertStatus(201)->assertJsonPath('data.question', 'هل الحصة التجريبية مجانية؟');
        $this->assertDatabaseHas('teacher_faqs', ['teacher_id' => $teacher->id, 'question' => 'هل الحصة التجريبية مجانية؟']);
        $faqId = $add->json('data.id');

        $remove = $this->as($token)->deleteJson("/api/teacher-faqs/{$faqId}");
        $remove->assertStatus(200);
        $this->assertDatabaseMissing('teacher_faqs', ['id' => $faqId]);
    }

    public function test_faqs_appear_on_the_public_profile_of_a_verified_teacher(): void
    {
        $user = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'teacher_type' => 'school', 'status' => 'verified']);
        $token = $user->createToken('t')->plainTextToken;

        $this->as($token)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'هل الحصص مباشرة؟',
            'answer' => 'نعم، جميع الحصص مباشرة بين الطالب والمعلم.',
        ])->assertStatus(201);

        $response = $this->getJson("/api/teachers/{$teacher->id}");
        $response->assertStatus(200)
            ->assertJsonPath('data.faqs.0.question', 'هل الحصص مباشرة؟')
            ->assertJsonPath('data.faqs.0.answer', 'نعم، جميع الحصص مباشرة بين الطالب والمعلم.');
    }

    public function test_cannot_add_more_than_the_maximum_allowed_faqs(): void
    {
        [$teacher, $token] = $this->createTeacher();

        for ($i = 0; $i < Teacher::MAX_FAQS; $i++) {
            $this->as($token)->postJson("/api/teachers/{$teacher->id}/faqs", [
                'question' => "سؤال رقم {$i}؟",
                'answer' => 'إجابة.',
            ])->assertStatus(201);
        }

        $overflow = $this->as($token)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'سؤال زائد؟',
            'answer' => 'إجابة.',
        ]);

        $overflow->assertStatus(422)->assertJsonValidationErrors('faqs');
        $this->assertSame(Teacher::MAX_FAQS, $teacher->faqs()->count());
    }

    /** لا يمكن لمعلم آخر (ليس صاحب الحساب ولا أدمن) إضافة/حذف سؤال لمعلم غيره */
    public function test_a_different_teacher_cannot_manage_another_teachers_faqs(): void
    {
        [$teacher] = $this->createTeacher();
        [, $intruderToken] = $this->createTeacher();

        $add = $this->as($intruderToken)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'سؤال؟',
            'answer' => 'إجابة.',
        ]);
        $add->assertStatus(403);

        $faq = $teacher->faqs()->create(['question' => 'سؤال؟', 'answer' => 'إجابة.', 'sort_order' => 0]);
        $remove = $this->as($intruderToken)->deleteJson("/api/teacher-faqs/{$faq->id}");
        $remove->assertStatus(403);
    }

    /** الأدمن يدير أسئلة أي معلم — نفس نمط بقية توسيعات "الإكمال نيابة عن المعلم" */
    public function test_admin_can_manage_a_teachers_faqs_on_their_behalf(): void
    {
        [$teacher] = $this->createTeacher();
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('t')->plainTextToken;

        $add = $this->as($adminToken)->postJson("/api/teachers/{$teacher->id}/faqs", [
            'question' => 'سؤال أضافه الأدمن؟',
            'answer' => 'إجابة أضافها الأدمن.',
        ]);
        $add->assertStatus(201);

        $remove = $this->as($adminToken)->deleteJson("/api/teacher-faqs/{$add->json('data.id')}");
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
