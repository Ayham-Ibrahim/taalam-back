<?php

namespace Tests\Feature\Package;

use App\Models\AvailabilitySlot;
use App\Models\Package;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_teacher_can_create_draft_and_get_approved_with_frozen_price(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'math', 'name_ar' => 'رياضيات', 'education_type' => 'school']);
        AvailabilitySlot::create(['teacher_id' => $teacher->id, 'day_of_week' => 3]);

        $create = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة رياضيات فردية',
            'description' => 'باقة لتقوية أساسيات الرياضيات',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 8,
            'teacher_price' => 100,
            'schedules' => [
                ['day_of_week' => 3],
            ],
        ]);

        $create->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $packageId = $create->json('data.id');

        // المعلم لا يرى نسبة المنصة ولا عائدها حتى في المسودة
        $create->assertJsonMissingPath('data.platform_margin_percent');
        $create->assertJsonMissingPath('data.platform_revenue');

        $submit = $this->as($teacherToken)->postJson("/api/packages/{$packageId}/submit");
        $submit->assertStatus(200)->assertJsonPath('data.status', 'pending_approval');
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.submitted']);

        [, $adminToken] = $this->createAdmin();

        $approve = $this->as($adminToken)->postJson("/api/packages/{$packageId}/approve", [
            'platform_margin_percent' => 60,
        ]);

        // teacher_price=100 سعر الساعة/الجلسة الواحدة × 8 جلسات = 800 قبل الهامش، ثم ×1.6 = 1280 للطالب
        $approve->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.platform_margin_percent', 60)
            ->assertJsonPath('data.student_price', 1280)
            ->assertJsonPath('data.platform_revenue', 480);

        $this->assertDatabaseHas('packages', [
            'id' => $packageId,
            'status' => 'active',
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 1280,
            'platform_revenue' => 480,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.approved']);

        // السعر النهائي مجمَّد: لا يمكن تعديل الباقة النشطة إطلاقاً
        $editAttempt = $this->as($teacherToken)->putJson("/api/packages/{$packageId}", [
            'title' => 'محاولة تعديل',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 8,
            'teacher_price' => 500,
        ]);
        $editAttempt->assertStatus(422);

        $this->assertDatabaseHas('packages', ['id' => $packageId, 'teacher_price' => 100, 'student_price' => 1280]);
    }

    public function test_admin_can_reject_pending_package_with_reason(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'phys', 'name_ar' => 'فيزياء', 'education_type' => 'school']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة فيزياء',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 5,
            'teacher_price' => 80,
            'status' => 'pending_approval',
            'submitted_at' => now(),
        ]);

        [, $adminToken] = $this->createAdmin();

        $reject = $this->as($adminToken)->postJson("/api/packages/{$package->id}/reject", [
            'reason' => 'السعر مرتفع جداً',
        ]);

        $reject->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('packages', [
            'id' => $package->id,
            'status' => 'rejected',
            'rejection_reason' => 'السعر مرتفع جداً',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.rejected']);
    }

    public function test_individual_capacity_must_be_one(): void
    {
        [, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'math2', 'name_ar' => 'رياضيات2', 'education_type' => 'school']);

        $response = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة خاطئة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 2,
            'sessions_count' => 8,
            'teacher_price' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['capacity']);
    }

    public function test_group_schedule_dates_must_equal_sessions_count_exactly(): void
    {
        [, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'math3b', 'name_ar' => 'رياضيات3ب', 'education_type' => 'school']);

        // sessions_count = 3 لكن تاريخان فقط — لا تكرار أسبوعي تلقائي بعد الآن
        $response = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة مجموعة',
            'description' => 'وصف الباقة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 5,
            'sessions_count' => 3,
            'teacher_price' => 100,
            'schedules' => [
                ['date' => now()->next(2)->toDateString(), 'start_time' => '09:00'],
                ['date' => now()->next(4)->toDateString(), 'start_time' => '09:00'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['schedules']);
    }

    public function test_group_capacity_must_be_at_least_two(): void
    {
        [, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'math3', 'name_ar' => 'رياضيات3', 'education_type' => 'school']);

        $response = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة مجموعة خاطئة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 1,
            'sessions_count' => 8,
            'teacher_price' => 100,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['capacity']);
    }

    public function test_training_center_cannot_create_package(): void
    {
        [, $centerToken] = $this->createVerifiedTeacher('training_center');
        $subject = Subject::create(['code' => 'biz', 'name_ar' => 'أعمال', 'education_type' => 'training']);

        $response = $this->as($centerToken)->postJson('/api/packages', [
            'title' => 'باقة من مركز',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 50,
        ]);

        $response->assertStatus(403);
    }

    /**
     * قبل هذا الإصلاح لم تكن CreatePackageRequest/UpdatePackageRequest تُعرِّفان
     * messages()، فتسقط كل رسائل التحقق للرسائل الافتراضية الإنجليزية من
     * Laravel — غير مفهومة لمستخدم عربي، وسبب مباشر في عدم معرفة أين الخطأ.
     */
    public function test_missing_required_fields_return_clear_arabic_messages(): void
    {
        [, $teacherToken] = $this->createVerifiedTeacher();

        $response = $this->as($teacherToken)->postJson('/api/packages', []);

        $response->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'عنوان الباقة مطلوب')
            ->assertJsonPath('errors.description.0', 'الوصف حقل إلزامي ولا يمكن أن يكون فارغاً')
            ->assertJsonPath('errors.subject_id.0', 'يرجى اختيار المادة')
            ->assertJsonPath('errors.sessions_count.0', 'يرجى تحديد عدد الجلسات')
            ->assertJsonPath('errors.teacher_price.0', 'يرجى تحديد سعر الساعة')
            ->assertJsonPath('errors.schedules.0', 'يرجى إضافة موعد واحد على الأقل');
    }

    /**
     * required وحدها لا ترفض قيمة "مسافات فقط" (Laravel يعتبرها "موجودة") —
     * prepareForValidation() يُقلّمها أولاً فتصبح سلسلة فارغة فعلياً ترفضها required.
     */
    public function test_a_whitespace_only_description_is_rejected_as_empty(): void
    {
        [, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'ws-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);

        $response = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة',
            'description' => '   ',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 50,
            'schedules' => [['day_of_week' => 3]],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.description.0', 'الوصف حقل إلزامي ولا يمكن أن يكون فارغاً');
        $this->assertDatabaseMissing('packages', ['title' => 'باقة']);
    }

    public function test_a_past_group_session_date_returns_a_clear_arabic_message(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'past-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);

        $response = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة بتاريخ ماضٍ',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 5,
            'sessions_count' => 1,
            'teacher_price' => 50,
            'schedules' => [
                ['date' => now()->subDay()->toDateString(), 'start_time' => '10:00'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame('لا يمكن اختيار تاريخ جلسة في الماضي', $response->json('errors')['schedules.0.date'][0]);
    }

    public function test_package_auto_closes_to_full_at_capacity_and_reopens_when_freed(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'group1', 'name_ar' => 'مجموعة', 'education_type' => 'school']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة مجموعة',
            'subject_id' => $subject->id,
            'session_format' => 'group',
            'capacity' => 2,
            'enrolled_count' => 1,
            'sessions_count' => 6,
            'teacher_price' => 100,
            'platform_margin_percent' => 60,
            'student_price' => 160,
            'platform_revenue' => 60,
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $package->update(['enrolled_count' => 2]);
        $this->assertSame('full', $package->fresh()->status);

        $package->update(['enrolled_count' => 1]);
        $this->assertSame('active', $package->fresh()->status);
    }

    public function test_non_owning_teacher_cannot_update_or_submit_others_package(): void
    {
        [$teacher] = $this->createVerifiedTeacher();
        [, $otherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'own1', 'name_ar' => 'مادة', 'education_type' => 'school']);

        $package = Package::create([
            'teacher_id' => $teacher->id,
            'title' => 'باقة أصلية',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 90,
            'status' => 'draft',
        ]);

        $update = $this->as($otherToken)->putJson("/api/packages/{$package->id}", [
            'title' => 'تعديل غير مصرح',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 90,
        ]);
        $update->assertStatus(403);

        $submit = $this->as($otherToken)->postJson("/api/packages/{$package->id}/submit");
        $submit->assertStatus(403);
    }

    public function test_admin_can_filter_packages_by_owner_but_teacher_cannot(): void
    {
        [$teacherA, $teacherAToken] = $this->createVerifiedTeacher();
        [$teacherB] = $this->createVerifiedTeacher();
        $subjectA = Subject::create(['code' => 'own-a', 'name_ar' => 'مادة أ', 'education_type' => 'school']);
        $subjectB = Subject::create(['code' => 'own-b', 'name_ar' => 'مادة ب', 'education_type' => 'school']);

        $packageA = Package::create([
            'teacher_id' => $teacherA->id,
            'title' => 'باقة أ',
            'subject_id' => $subjectA->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 90,
            'status' => 'draft',
        ]);

        Package::create([
            'teacher_id' => $teacherB->id,
            'title' => 'باقة ب',
            'subject_id' => $subjectB->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 90,
            'status' => 'draft',
        ]);

        [, $adminToken] = $this->createAdmin();

        $filtered = $this->as($adminToken)->getJson("/api/packages?owner={$teacherA->id}");
        $filtered->assertStatus(200);
        $ids = collect($filtered->json('data'))->pluck('id');
        $this->assertSame([$packageA->id], $ids->all());

        // معلم لا يملك صلاحية تصفية باقات معلم آخر — يرى باقاته فقط بصرف النظر عن owner
        $asTeacher = $this->as($teacherAToken)->getJson("/api/packages?owner={$teacherB->id}");
        $asTeacher->assertStatus(200);
        $teacherIds = collect($asTeacher->json('data'))->pluck('id');
        $this->assertSame([$packageA->id], $teacherIds->all());
    }

    /**
     * grades عمود JSON مباشر على الباقة (لا علاقة pivot)، بلا جدول تصنيف —
     * أرقام صِرفة 1-12 تماماً كـ students.grade. مصفوفة فارغة تُطبَّع لـ null
     * (PackageService::updateDraft) بدل تركها [] حرفياً.
     */
    public function test_package_stores_grades_and_they_can_be_cleared_on_update(): void
    {
        [$teacher, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'grd-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);
        AvailabilitySlot::create(['teacher_id' => $teacher->id, 'day_of_week' => 2]);

        $create = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة بصفوف محددة',
            'description' => 'وصف الباقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 50,
            'grades' => [7, 8, 9],
            'schedules' => [['day_of_week' => 2]],
        ]);

        $create->assertStatus(201)->assertJsonPath('data.grades', [7, 8, 9]);
        $packageId = $create->json('data.id');
        $this->assertSame([7, 8, 9], Package::find($packageId)->grades);

        $update = $this->as($teacherToken)->putJson("/api/packages/{$packageId}", [
            'title' => 'باقة بصفوف محددة',
            'description' => 'وصف الباقة',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 50,
            'grades' => [],
            'schedules' => [['day_of_week' => 2]],
        ]);

        $update->assertStatus(200);
        $this->assertNull(Package::find($packageId)->grades);
    }

    public function test_grade_must_be_between_one_and_twelve(): void
    {
        [, $teacherToken] = $this->createVerifiedTeacher();
        $subject = Subject::create(['code' => 'grd2-'.uniqid(), 'name_ar' => 'مادة', 'education_type' => 'school']);

        $response = $this->as($teacherToken)->postJson('/api/packages', [
            'title' => 'باقة',
            'description' => 'وصف',
            'subject_id' => $subject->id,
            'session_format' => 'individual',
            'capacity' => 1,
            'sessions_count' => 4,
            'teacher_price' => 50,
            'grades' => [13],
            'schedules' => [['day_of_week' => 2]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['grades.0']);
    }

    /**
     * @return array{0: Teacher, 1: string}
     */
    private function createVerifiedTeacher(string $type = 'school'): array
    {
        $teacherUser = User::factory()->teacher()->create();
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'teacher_type' => $type, 'status' => 'verified']);
        $token = $teacherUser->createToken('t')->plainTextToken;

        return [$teacher, $token];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createAdmin(): array
    {
        $admin = User::factory()->admin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }
}
