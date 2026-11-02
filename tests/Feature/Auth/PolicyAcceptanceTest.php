<?php

namespace Tests\Feature\Auth;

use App\Models\LegalPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** الموافقة الصريحة على سياسة الدور عند التسجيل، وصفحتا السياسة المُدرجتان عبر migration */
class PolicyAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function studentPayload(array $overrides = []): array
    {
        return [
            'name' => 'Test Student',
            'email' => 'policy-student@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'education_type' => 'training',
            'level' => 'beginner',
            'course_field_id' => DB::table('course_fields')->insertGetId([
                'code' => 'lang-'.uniqid(), 'name_ar' => 'لغات', 'created_at' => now(), 'updated_at' => now(),
            ]),
            ...$overrides,
        ];
    }

    private function teacherPayload(array $overrides = []): array
    {
        return [
            'name' => 'Test Teacher',
            'email' => 'policy-teacher@example.com',
            'teacher_type' => 'school',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            ...$overrides,
        ];
    }

    public function test_policy_pages_are_seeded_by_migration_and_public(): void
    {
        foreach ([LegalPage::STUDENT_POLICY, LegalPage::TEACHER_POLICY] as $slug) {
            $this->getJson("/api/legal-pages/{$slug}")
                ->assertOk()
                ->assertJsonPath('data.slug', $slug)
                ->assertJsonStructure(['data' => ['sections' => [['title_ar', 'title_en', 'body_ar', 'body_en']]]]);
        }
    }

    public function test_student_registration_requires_accepting_the_policy(): void
    {
        $this->postJson('/api/auth/register/student', $this->studentPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accept_policy']);

        $this->postJson('/api/auth/register/student', $this->studentPayload(['accept_policy' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accept_policy']);

        $this->assertDatabaseMissing('users', ['email' => 'policy-student@example.com']);
    }

    public function test_teacher_registration_requires_accepting_the_policy(): void
    {
        $this->postJson('/api/auth/register/teacher', $this->teacherPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accept_policy']);

        $this->assertDatabaseMissing('users', ['email' => 'policy-teacher@example.com']);
    }

    public function test_registration_stores_acceptance_time_and_accepted_policy_version(): void
    {
        $studentPolicyVersion = LegalPage::where('slug', LegalPage::STUDENT_POLICY)->value('updated_at');
        $teacherPolicyVersion = LegalPage::where('slug', LegalPage::TEACHER_POLICY)->value('updated_at');

        $this->postJson('/api/auth/register/student', $this->studentPayload(['accept_policy' => true]))->assertCreated();
        $this->postJson('/api/auth/register/teacher', $this->teacherPayload(['accept_policy' => true]))->assertCreated();

        $student = User::where('email', 'policy-student@example.com')->first();
        $teacher = User::where('email', 'policy-teacher@example.com')->first();

        $this->assertNotNull($student->policy_accepted_at);
        $this->assertEquals($studentPolicyVersion, $student->policy_version);
        $this->assertNotNull($teacher->policy_accepted_at);
        $this->assertEquals($teacherPolicyVersion, $teacher->policy_version);
    }
}
