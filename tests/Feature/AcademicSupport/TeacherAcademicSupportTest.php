<?php

namespace Tests\Feature\AcademicSupport;

use App\Models\AcademicSupporter;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المدرس الجامعي حصراً: الأدمن يفعّل له باقات دعم أكاديمي (ملف داعم مرتبط)، فيظهر كارده
 * للطالب الجامعي بتاب الدعم الأكاديمي متى كان موثّقاً ولديه باقة نشطة.
 */
class TeacherAcademicSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_support_packages_to_a_university_teacher_only(): void
    {
        $adminToken = User::factory()->admin()->create()->createToken('t')->plainTextToken;
        $teacher = $this->createTeacher('university', 'verified', bio: 'مدرس رياضيات جامعي');

        $link = $this->withToken($adminToken)->postJson("/api/teachers/{$teacher->id}/academic-supporter");
        $link->assertOk();
        $supporterId = $link->json('data.academic_supporter_id');

        $this->assertDatabaseHas('academic_supporters', [
            'id' => $supporterId,
            'teacher_id' => $teacher->id,
            'user_id' => $teacher->user_id,
            'bio' => 'مدرس رياضيات جامعي',
            'status' => 'active',
        ]);

        // idempotent: نفس الملف عند التكرار
        $this->withToken($adminToken)->postJson("/api/teachers/{$teacher->id}/academic-supporter")
            ->assertOk()
            ->assertJsonPath('data.academic_supporter_id', $supporterId);

        // تُضاف الباقة بنفس مسار باقات الدعم الحالي
        $subject = Subject::create(['code' => 'ts-'.uniqid(), 'name_ar' => 'تفاضل', 'education_type' => 'university']);
        $this->withToken($adminToken)->postJson('/api/support-packages', [
            'academic_supporter_id' => $supporterId,
            'title' => 'دعم التفاضل',
            'tier' => 'basic',
            'subject_ids' => [$subject->id],
            'price' => 1000,
            'supporter_profit_percent' => 60,
            'description' => 'متابعة واجبات التفاضل',
        ])->assertCreated();

        $this->getJson("/api/teachers/{$teacher->id}/admin-detail", ['Authorization' => "Bearer {$adminToken}"])
            ->assertJsonPath('data.teacher.academicSupporterId', $supporterId);

        // ملفات المدرسين لا تظهر كحسابات داعمين منفصلة بقائمة الأدمن
        $this->assertSame([], $this->withToken($adminToken)->getJson('/api/academic-supporters')->json('data'));

        // مدرس مدرسي ومركز تدريبي: مرفوض
        foreach (['school', 'training_center'] as $type) {
            $other = $this->createTeacher($type, 'verified');
            $this->withToken($adminToken)->postJson("/api/teachers/{$other->id}/academic-supporter")
                ->assertStatus(422)
                ->assertJsonValidationErrors(['teacher_type']);
        }
    }

    public function test_only_admin_can_enable_support_packages_for_a_teacher(): void
    {
        $teacher = $this->createTeacher('university', 'verified');
        $teacherToken = $teacher->user->createToken('t')->plainTextToken;

        $this->withToken($teacherToken)->postJson("/api/teachers/{$teacher->id}/academic-supporter")->assertForbidden();
        $this->assertDatabaseCount('academic_supporters', 0);
    }

    public function test_university_student_sees_teacher_card_only_when_verified_with_an_active_package(): void
    {
        $studentToken = $this->createUniversityStudentToken();
        $teacher = $this->createTeacher('university', 'verified');
        $supporter = AcademicSupporter::create(['user_id' => $teacher->user_id, 'teacher_id' => $teacher->id, 'status' => 'active']);

        $standalone = AcademicSupporter::create(['user_id' => User::factory()->create(['role' => 'academic_support'])->id, 'status' => 'active']);

        // بلا باقة: يظهر الداعم المستقل فقط
        $this->assertSearchIds($studentToken, [$standalone->id]);
        $this->withToken($studentToken)->getJson("/api/academic-supporters/{$supporter->id}")->assertNotFound();

        $package = $this->createPackage($supporter, 'active');

        $this->assertSearchIds($studentToken, [$standalone->id, $supporter->id]);
        $this->withToken($studentToken)->getJson("/api/academic-supporters/{$supporter->id}")
            ->assertOk()
            ->assertJsonPath('data.teacherId', $teacher->id)
            ->assertJsonPath('data.supportPackages.0.id', $package->id);

        // باقة معطّلة فقط أو مدرس معلَّق: يختفي
        $package->update(['status' => 'disabled']);
        $this->assertSearchIds($studentToken, [$standalone->id]);

        $package->update(['status' => 'active']);
        $teacher->update(['status' => 'suspended']);
        $this->assertSearchIds($studentToken, [$standalone->id]);
    }

    public function test_teacher_public_profile_lists_active_support_packages_for_everyone(): void
    {
        $teacher = $this->createTeacher('university', 'verified');
        $supporter = AcademicSupporter::create(['user_id' => $teacher->user_id, 'teacher_id' => $teacher->id, 'status' => 'active']);
        $active = $this->createPackage($supporter, 'active');
        $this->createPackage($supporter, 'disabled');

        // بلا تسجيل دخول — كبقية باقات المدرس ببروفايله العام
        $this->getJson("/api/teachers/{$teacher->id}/support-packages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.academicSupporterId', $supporter->id)
            ->assertJsonPath('data.0.price', 1000);

        // مدرس غير موثّق، أو بلا ملف داعم: قائمة فارغة
        $teacher->update(['status' => 'suspended']);
        $this->getJson("/api/teachers/{$teacher->id}/support-packages")->assertOk()->assertJsonCount(0, 'data');

        $plain = $this->createTeacher('university', 'verified');
        $this->getJson("/api/teachers/{$plain->id}/support-packages")->assertOk()->assertJsonCount(0, 'data');
    }

    private function assertSearchIds(string $token, array $expected): void
    {
        $ids = collect($this->withToken($token)->getJson('/api/academic-supporters/search')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    private function createPackage(AcademicSupporter $supporter, string $status)
    {
        return $supporter->supportPackages()->create([
            'title' => 'باقة '.uniqid(),
            'tier' => 'basic',
            'description' => 'وصف',
            'student_price' => 1000,
            'supporter_profit_percent' => 50,
            'platform_margin_percent' => 50,
            'teacher_price' => 500,
            'platform_revenue' => 500,
            'status' => $status,
        ]);
    }

    private function createTeacher(string $type, string $status, ?string $bio = null): Teacher
    {
        $user = User::factory()->teacher()->create();

        return Teacher::create(['user_id' => $user->id, 'teacher_type' => $type, 'status' => $status, 'bio' => $bio]);
    }

    private function createUniversityStudentToken(): string
    {
        $user = User::factory()->student()->create();
        Student::create(['user_id' => $user->id, 'education_type' => 'university']);

        return $user->createToken('t')->plainTextToken;
    }
}
